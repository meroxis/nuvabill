<?php

namespace App\Seo;

use App\Support\Locales;

/**
 * Which language versions of a page are offered to search engines. With "Only link languages
 * that are translated" on, a page that says which languages it is ready in (see
 * Seo::setReadyLanguages()) offers only those; the other versions point to the main language.
 */
class LanguageVersions
{
    /**
     * Languages to link for one page: none when "Link the language versions" is off or fewer
     * than two qualify.
     *
     * @param  list<string>|null  $ready  The page's ready languages, or null when every language is.
     * @return list<string>
     */
    public static function offered(?array $ready): array
    {
        if (! setting('seo.language_links')) {
            return [];
        }

        $locales = array_keys(Locales::enabled());

        if ($ready !== null && setting('seo.ready_languages_only')) {
            $default = Locales::default();
            $locales = array_values(array_filter($locales, fn (string $locale): bool => $locale === $default || in_array($locale, $ready, true)));
        }

        return count($locales) >= 2 ? $locales : [];
    }

    /**
     * Whether ?lang=$locale is a page of its own for search engines, or a copy of the main language.
     *
     * @param  list<string>|null  $ready
     */
    public static function isOwnVersion(string $locale, ?array $ready): bool
    {
        return $locale === Locales::default()
            || ! setting('seo.ready_languages_only')
            || $ready === null
            || in_array($locale, $ready, true);
    }
}
