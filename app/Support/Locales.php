<?php

namespace App\Support;

use App\Models\Admin;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Locale;

/**
 * The languages Nuvabill speaks: the ones WHMCS ships, plus Kurdish (Sorani). Arabic, Hebrew and
 * Kurdish are written right to left; right-to-left languages show prices in Latin digits so
 * invoices and payments read the same everywhere.
 */
class Locales
{
    /**
     * In the order people see them: by their own name, Latin letters first.
     *
     * @var array<string, array{name: string, native: string, short: string, rtl: bool}>
     */
    public const ALL = [
        'az' => ['name' => 'Azerbaijani', 'native' => 'Azərbaycanca', 'short' => 'AZ', 'rtl' => false],
        'ca' => ['name' => 'Catalan', 'native' => 'Català', 'short' => 'CA', 'rtl' => false],
        'cs' => ['name' => 'Czech', 'native' => 'Čeština', 'short' => 'CS', 'rtl' => false],
        'da' => ['name' => 'Danish', 'native' => 'Dansk', 'short' => 'DA', 'rtl' => false],
        'de' => ['name' => 'German', 'native' => 'Deutsch', 'short' => 'DE', 'rtl' => false],
        'et' => ['name' => 'Estonian', 'native' => 'Eesti', 'short' => 'ET', 'rtl' => false],
        'en' => ['name' => 'English', 'native' => 'English', 'short' => 'EN', 'rtl' => false],
        'es' => ['name' => 'Spanish', 'native' => 'Español', 'short' => 'ES', 'rtl' => false],
        'fr' => ['name' => 'French', 'native' => 'Français', 'short' => 'FR', 'rtl' => false],
        'hr' => ['name' => 'Croatian', 'native' => 'Hrvatski', 'short' => 'HR', 'rtl' => false],
        'it' => ['name' => 'Italian', 'native' => 'Italiano', 'short' => 'IT', 'rtl' => false],
        'hu' => ['name' => 'Hungarian', 'native' => 'Magyar', 'short' => 'HU', 'rtl' => false],
        'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'short' => 'NL', 'rtl' => false],
        'nb' => ['name' => 'Norwegian', 'native' => 'Norsk', 'short' => 'NO', 'rtl' => false],
        'pt_BR' => ['name' => 'Portuguese (Brazil)', 'native' => 'Português (Brasil)', 'short' => 'BR', 'rtl' => false],
        'pt_PT' => ['name' => 'Portuguese (Portugal)', 'native' => 'Português (Portugal)', 'short' => 'PT', 'rtl' => false],
        'ro' => ['name' => 'Romanian', 'native' => 'Română', 'short' => 'RO', 'rtl' => false],
        'sv' => ['name' => 'Swedish', 'native' => 'Svenska', 'short' => 'SV', 'rtl' => false],
        'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'short' => 'TR', 'rtl' => false],
        'mk' => ['name' => 'Macedonian', 'native' => 'Македонски', 'short' => 'MK', 'rtl' => false],
        'ru' => ['name' => 'Russian', 'native' => 'Русский', 'short' => 'RU', 'rtl' => false],
        'uk' => ['name' => 'Ukrainian', 'native' => 'Українська', 'short' => 'UK', 'rtl' => false],
        'he' => ['name' => 'Hebrew', 'native' => 'עברית', 'short' => 'HE', 'rtl' => true],
        'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'short' => 'AR', 'rtl' => true],
        'ckb' => ['name' => 'Kurdish (Sorani)', 'native' => 'کوردی', 'short' => 'KU', 'rtl' => true],
        'zh_CN' => ['name' => 'Chinese (Simplified)', 'native' => '简体中文', 'short' => 'ZH', 'rtl' => false],
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

    /**
     * The code for HTML lang attributes, such as pt-BR.
     */
    public static function htmlLang(?string $locale = null): string
    {
        return str_replace('_', '-', $locale ?? app()->getLocale());
    }

    /**
     * The name of a language in the language of the page, such as "German" or "Allemand",
     * from the server's language data, with the English name when that is missing.
     */
    public static function displayName(string $locale): string
    {
        $english = self::ALL[$locale]['name'] ?? $locale;

        if (! class_exists(Locale::class)) {
            return $english;
        }

        $name = (string) Locale::getDisplayName(self::htmlLang($locale), self::htmlLang());

        return $name === '' || strcasecmp($name, $locale) === 0 || strcasecmp($name, self::htmlLang($locale)) === 0 ? $english : $name;
    }

    /**
     * A label used inside a sentence, such as "Featured theme": lower case, except in German,
     * where nouns keep their capital letter.
     */
    public static function inSentence(string $label): string
    {
        return app()->getLocale() === 'de' ? $label : mb_strtolower($label);
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

        // A link to a language version (?lang=de, as search engines are given) wins and is remembered.
        $linked = $adminArea ? null : $request->query('lang');

        if (is_string($linked) && isset($enabled[$linked])) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_CLIENT, $linked);
            }

            return $linked;
        }

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
