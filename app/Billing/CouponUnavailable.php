<?php

namespace App\Billing;

use Illuminate\Validation\ValidationException;

/**
 * The coupon stopped working while the order was being placed, for example another checkout took
 * its last use. Nothing of the order was made. Shown to the client as an error on the coupon.
 */
class CouponUnavailable extends ValidationException
{
    public static function because(string $reason): static
    {
        return static::withMessages(['coupon' => $reason]);
    }

    /**
     * Why the coupon cannot be used, in words for the client.
     */
    public function reason(): string
    {
        return (string) $this->validator->errors()->first('coupon');
    }
}
