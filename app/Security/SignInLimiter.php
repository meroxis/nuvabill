<?php

namespace App\Security;

use App\Support\Demo;
use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Counts wrong passwords and wrong two-factor codes per account. The routes also limit each IP
 * address, but guesses spread over many addresses only stop with a limit on the account itself.
 *
 * Wrong passwords are counted twice: for the email address from one IP address, and for the email
 * address from all IP addresses together. The first stops one guesser quickly. The second is much
 * higher, so strangers cannot easily keep the owner out, and a password reset lets the owner past it.
 *
 * Each check runs inside onePasswordAtATime() or oneCodeAtATime(), so the limit check, the
 * password or code check and the count happen as one step per account. Without that, guesses sent
 * at the same moment would all pass the limit check before any of them was counted.
 *
 * $area is "admin" for staff and "client" for clients, so the two never share a count.
 */
final class SignInLimiter
{
    /** Wrong passwords for one email address from one IP address before that address has to wait. */
    public const PASSWORDS = 10;

    /** Wrong passwords for one email address from all IP addresses together before it has to wait. */
    public const ACCOUNT_PASSWORDS = 50;

    /** Wrong two-factor codes for one account before it has to wait. */
    public const CODES = 5;

    /** How long the wait lasts, in seconds. */
    public const WAIT = 900;

    /** How long the count for one email address from all IP addresses lasts, in seconds. */
    public const ACCOUNT_WAIT = 3600;

    /** How long one check may hold an account at most, in seconds. */
    private const LOCK_SECONDS = 10;

    /** How long the next request waits for the account before it gets "Too many requests", in seconds. */
    private const LOCK_WAIT = 5;

    /**
     * Run $check while no other password check for this email address runs.
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     *
     * @throws ThrottleRequestsException when other checks keep the address busy for too long
     */
    public static function onePasswordAtATime(string $area, string $email, Closure $check): mixed
    {
        return self::oneAtATime(self::accountKey($area, $email), $check);
    }

    /**
     * Run $check while no other two-factor code check for this account runs.
     *
     * @template T
     *
     * @param  Closure(): T  $check
     * @return T
     *
     * @throws ThrottleRequestsException when other checks keep the account busy for too long
     */
    public static function oneCodeAtATime(string $area, int $id, Closure $check): mixed
    {
        return self::oneAtATime(self::codeKey($area, $id), $check);
    }

    /**
     * Why a password for this email address is not checked now, or null when it is.
     *
     * Too many wrong passwords from this IP address stop that address, even with the right password.
     * Too many from all addresses together stop every address, except the browser that just reset
     * the password with the emailed link: the link proved that browser belongs to the owner.
     */
    public static function passwordRefused(Request $request, string $area, string $email): ?string
    {
        if (RateLimiter::tooManyAttempts(self::passwordKey($area, $email, $request), self::PASSWORDS)) {
            return __('Too many tries. Wait 15 minutes, then try again.');
        }

        if (RateLimiter::tooManyAttempts(self::accountKey($area, $email), self::ACCOUNT_PASSWORDS)
            && ! self::resetInThisBrowser($request, $area, $email)
            && ! self::isDemoSignIn($email)) {
            return __('Too many wrong passwords for this email. Reset your password to sign in now, or wait an hour.');
        }

        return null;
    }

    public static function passwordFailed(Request $request, string $area, string $email): void
    {
        RateLimiter::hit(self::passwordKey($area, $email, $request), self::WAIT);
        RateLimiter::hit(self::accountKey($area, $email), self::ACCOUNT_WAIT);
    }

    public static function passwordPassed(Request $request, string $area, string $email): void
    {
        RateLimiter::clear(self::passwordKey($area, $email, $request));
        RateLimiter::clear(self::accountKey($area, $email));

        if ($request->hasSession()) {
            $request->session()->forget(self::resetSessionKey($area));
        }
    }

    /**
     * The password was reset with the emailed link. The counts are cleared, and this browser gets past
     * the count from all IP addresses until it signs in, so strangers who keep guessing cannot keep the
     * owner out. Wrong passwords from this browser are still counted, and its IP address keeps its limit.
     */
    public static function passwordReset(Request $request, string $area, string $email): void
    {
        RateLimiter::clear(self::passwordKey($area, $email, $request));
        RateLimiter::clear(self::accountKey($area, $email));

        if ($request->hasSession()) {
            $request->session()->put(self::resetSessionKey($area), self::emailHash($email));
        }
    }

    public static function codesLocked(string $area, int $id): bool
    {
        return RateLimiter::tooManyAttempts(self::codeKey($area, $id), self::CODES);
    }

    /**
     * Count a wrong code. True when this was the last try, so the sign-in must stop now.
     */
    public static function codeFailed(string $area, int $id): bool
    {
        return RateLimiter::hit(self::codeKey($area, $id), self::WAIT) >= self::CODES;
    }

    public static function codePassed(string $area, int $id): void
    {
        RateLimiter::clear(self::codeKey($area, $id));
    }

    /**
     * The lock lives in the same cache as the counts (file, database or Redis), so it holds across
     * every PHP process of the site.
     */
    private static function oneAtATime(string $key, Closure $callback): mixed
    {
        $cache = Cache::store(config('cache.limiter'));

        if (! $cache->getStore() instanceof LockProvider) {
            return $callback();
        }

        try {
            return $cache->lock($key.':check', self::LOCK_SECONDS)->block(self::LOCK_WAIT, $callback);
        } catch (LockTimeoutException) {
            throw new ThrottleRequestsException('', null, ['Retry-After' => self::LOCK_WAIT]);
        }
    }

    private static function resetInThisBrowser(Request $request, string $area, string $email): bool
    {
        $reset = $request->hasSession() ? $request->session()->get(self::resetSessionKey($area)) : null;

        return is_string($reset) && hash_equals($reset, self::emailHash($email));
    }

    /**
     * The shared sign-ins of the public demo. Their password is shown on the sign-in page, so counting
     * guesses protects nothing, and one visitor could keep every other visitor out.
     */
    private static function isDemoSignIn(string $email): bool
    {
        return Demo::isEnabled() && in_array(Str::lower(trim($email)), [Demo::ADMIN_EMAIL, Demo::CLIENT_EMAIL], true);
    }

    private static function resetSessionKey(string $area): string
    {
        return $area.'.reset_unlock';
    }

    /**
     * Keyed by the email typed, whether or not an account uses it, so the answer never shows which
     * addresses have an account. The one-at-a-time lock uses this key too.
     */
    private static function accountKey(string $area, string $email): string
    {
        return $area.'-login:'.self::emailHash($email);
    }

    private static function passwordKey(string $area, string $email, Request $request): string
    {
        return $area.'-login-ip:'.sha1(self::emailHash($email).'|'.$request->ip());
    }

    private static function emailHash(string $email): string
    {
        return sha1(Str::lower(trim($email)));
    }

    private static function codeKey(string $area, int $id): string
    {
        return $area.'-2fa:'.$id;
    }
}
