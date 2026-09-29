<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Helpers for staff-written pages (knowledge base, announcements): the languages they can be
 * translated into, and web addresses made from titles.
 */
class ContentLanguages
{
    /**
     * The site's other switched-on languages, code => name in that language. Staff write the main
     * text in the default language.
     *
     * @return array<string, string>
     */
    public static function others(): array
    {
        $default = Locales::default();

        return array_filter(Locales::enabled(), fn (string $code): bool => $code !== $default, ARRAY_FILTER_USE_KEY);
    }

    /**
     * The language chosen with ?lang=, or null for the main text.
     */
    public static function chosen(?string $locale): ?string
    {
        return $locale !== null && isset(self::others()[$locale]) ? $locale : null;
    }

    /**
     * A free web address made from the title: "reset-your-password", or "...-2" when taken.
     *
     * @param  class-string<Model>  $model
     * @param  list<string>  $reserved
     */
    public static function slug(string $model, string $title, ?int $ignore = null, array $reserved = []): string
    {
        $base = Str::limit(Str::slug($title), 180, '') ?: 'page';
        $base = trim($base, '-') ?: 'page';
        $slug = $base;
        $number = 2;

        while (in_array($slug, $reserved, true) || $model::query()->where('slug', $slug)->when($ignore, fn ($query) => $query->whereKeyNot($ignore))->exists()) {
            $slug = $base.'-'.$number++;
        }

        return $slug;
    }
}
