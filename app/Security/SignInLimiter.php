<?php

namespace App\Security;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Counts wrong passwords and wrong two-factor codes per account. The routes also limit each IP
 * address, but guesses spread over many addresses only stop with a limit on the account itself.
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
