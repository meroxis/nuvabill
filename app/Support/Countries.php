<?php

namespace App\Support;

use ResourceBundle;
use Throwable;

/**
 * Country names by ISO 3166 code, in the current language, from PHP's intl data.
 */
class Countries
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $cache = [];

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $locale = app()->getLocale();

        if (isset(self::$cache[$locale])) {
            return self::$cache[$locale];
        }

        $countries = [];

        try {
            $bundle = ResourceBundle::create($locale, 'ICUDATA-region');
            $table = $bundle?->get('Countries');

            foreach ($table ?? [] as $code => $name) {
                if (is_string($code) && preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['EU', 'EZ', 'UN', 'QO', 'XA', 'XB', 'ZZ'], true)) {
                    $countries[$code] = (string) $name;
                }
            }
        } catch (Throwable) {
            $countries = [];
        }

        if ($countries === []) {
            $countries = ['US' => 'United States', 'GB' => 'United Kingdom', 'DE' => 'Germany', 'IQ' => 'Iraq', 'TR' => 'Türkiye'];
        }

        asort($countries, SORT_LOCALE_STRING);

        return self::$cache[$locale] = $countries;
    }

    public static function name(?string $code): ?string
    {
        return $code === null ? null : (self::all()[$code] ?? $code);
    }
}
