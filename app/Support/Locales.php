<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;

/**
 * The languages Nuvabill speaks. Arabic and Kurdish (Sorani) are written right to left; numbers
 * and prices stay in Latin digits in every language so invoices and payments read the same.
 */
class Locales
{
    /**
     * @var array<string, array{name: string, native: string, rtl: bool}>
     */
    public const ALL = [
        'en' => ['name' => 'English', 'native' => 'English', 'rtl' => false],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'rtl' => true],
        'ckb' => ['name' => 'Kurdish (Sorani)', 'native' => 'کوردی', 'rtl' => true],
    ];

    public const SESSION_CLIENT = 'locale';

    public const SESSION_ADMIN = 'admin_locale';

    /**
     * Languages people can pick, as code => name in that language.
     *
     * @return array<string, string>
     */
    public static function enabled(): array
    {
        $enabled = array_intersect(array_keys(self::ALL), (array) setting('locale.enabled'));
        $enabled = $enabled === [] ? ['en'] : $enabled;
        $default = self::default();

        if (! in_array($default, $enabled, true)) {
            array_unshift($enabled, $default);
        }

        return collect($enabled)->mapWithKeys(fn (string $code): array => [$code => self::ALL[$code]['native']])->all();
    }

    public static function default(): string
    {
        $default = (string) setting('locale.default');

        return isset(self::ALL[$default]) ? $default : 'en';
    }

    public static function isSupported(?string $locale): bool
    {
        return $locale !== null && isset(self::ALL[$locale]);
    }

    public static function isRtl(?string $locale = null): bool
    {
        return self::ALL[$locale ?? app()->getLocale()]['rtl'] ?? false;
    }

    public static function direction(): string
    {
        return self::isRtl() ? 'rtl' : 'ltr';
    }

    /**
     * The locale for numbers and prices: right-to-left languages use English formats so digits
     * stay Latin (1,250.00) instead of Arabic-Indic.
     */
    public static function numberLocale(): string
    {
        return self::isRtl() ? 'en' : app()->getLocale();
    }

    /**
     * Pick the language for this request: the choice made in this browser, then the one saved
     * on the account, then the site default.
     */
    public static function forRequest(Request $request, bool $adminArea): string
    {
        $enabled = self::enabled();
        $person = $adminArea ? $request->user('admin') : $request->user('web');
        $choices = [
            $request->hasSession() ? $request->session()->get($adminArea ? self::SESSION_ADMIN : self::SESSION_CLIENT) : null,
            $person instanceof Admin || $person instanceof Client ? $person->language : null,
        ];

        foreach ($choices as $choice) {
            if (is_string($choice) && ($adminArea ? self::isSupported($choice) : isset($enabled[$choice]))) {
                return $choice;
            }
        }

        return self::default();
    }

    /**
     * Run something in English, like PDFs (the PDF engine cannot join Arabic letters).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function inEnglish(Closure $callback): mixed
    {
        $previous = app()->getLocale();

        if ($previous === 'en') {
            return $callback();
        }

        app()->setLocale('en');

        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
