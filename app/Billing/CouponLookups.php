<?php

namespace App\Billing;

use App\Models\Coupon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

/**
 * How many different coupon codes one visitor may look up in a minute, through coupon links and
 * the order form's price checks together. It stops guessing codes. Checking the same code again
 * (the order form does that on every change) does not count.
 */
class CouponLookups
{
    public const PER_MINUTE = 15;

    public static function allow(?string $ip, string $code): bool
    {
        $key = 'coupon-lookup:'.$ip;
        $seen = $key.':'.sha1(Coupon::normalize($code));

        if (Cache::has($seen)) {
            return true;
        }

        if (RateLimiter::tooManyAttempts($key, self::PER_MINUTE)) {
            return false;
        }

        RateLimiter::hit($key, 60);
        Cache::put($seen, true, 60);

        return true;
    }
}
