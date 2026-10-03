<?php

namespace App\Support;

use Illuminate\Support\Number;
use InvalidArgumentException;

/**
 * Money is stored as integer minor units (cents). These helpers convert and format it.
 */
class Money
{
    /**
     * Some currencies, such as IQD, show whole units by default. An amount with cents is still
     * shown with them, so the shown amount is always the amount stored and owed.
     */
    public static function format(int $minor, string $currency): string
    {
        return Number::currency($minor / 100, in: $currency, locale: Locales::numberLocale(), precision: $minor % 100 === 0 ? null : 2);
    }

    /**
     * Convert a typed amount such as "12.50" or "1,200" into minor units.
     */
    public static function toMinor(string|int|float|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        $normalized = str_replace([',', ' '], '', (string) $amount);

        if (! is_numeric($normalized)) {
            throw new InvalidArgumentException("[{$amount}] is not a valid amount.");
        }

        return (int) round(((float) $normalized) * 100);
    }

    /**
     * Minor units as a plain decimal string for form fields, for example 1250 => "12.50".
     */
    public static function toDecimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
