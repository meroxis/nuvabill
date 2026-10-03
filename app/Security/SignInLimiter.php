<?php

namespace App\Security;

use Closure;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Counts wrong passwords and wrong two-factor codes per account. The routes also limit each IP
 * address, but guesses spread over many addresses only stop with a limit on the account itself.
 *
 * Each check runs inside onePasswordAtATime() or oneCodeAtATime(), so the limit check, the
 * password or code check and the count happen as one step per account. Without that, guesses sent
 * at the same moment would all pass the limit check before any of them was counted.
 *
 * $area is "admin" for staff and "client" for clients, so the two never share a count.
 */
final class SignInLimiter
{
    /** Wrong passwords for one email address before it has to wait. */
    public const PASSWORDS = 10;

    /** Wrong two-factor codes for one account before it has to wait. */
    public const CODES = 5;

    /** How long the wait lasts, in seconds. */
    public const WAIT = 900;

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
        return self::oneAtATime(self::passwordKey($area, $email), $check);
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

    public static function passwordLocked(string $area, string $email): bool
    {
        return RateLimiter::tooManyAttempts(self::passwordKey($area, $email), self::PASSWORDS);
    }

    public static function passwordFailed(string $area, string $email): void
    {
        RateLimiter::hit(self::passwordKey($area, $email), self::WAIT);
    }

    public static function passwordPassed(string $area, string $email): void
    {
        RateLimiter::clear(self::passwordKey($area, $email));
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

    /**
     * Keyed by the email typed, whether or not an account uses it, so the answer never shows which
     * addresses have an account.
     */
    private static function passwordKey(string $area, string $email): string
    {
        return $area.'-login:'.sha1(Str::lower(trim($email)));
    }

    private static function codeKey(string $area, int $id): string
    {
        return $area.'-2fa:'.$id;
    }
}
