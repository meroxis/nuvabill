<?php

namespace App\Seo;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SeoTranslation;
use App\Support\ContentLanguages;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * Search titles and descriptions in the site's other languages, for the home page, store groups
 * and products. The main language stays where it was (settings, products and groups). A language
 * version is "ready", and offered to search engines, once both texts are written in it.
 *
 * Before the update that adds the table has run, every page simply has no translations.
 */
class SeoTranslations
{
    public const HOME = 'home';

    public const TITLE_LIMIT = 120;

    public const DESCRIPTION_LIMIT = 320;

    /**
     * Form rules for the "Other languages" fields (seo_translations[de][title] and so on). Only the
     * site's other switched-on languages are allowed; anything else is refused.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        $codes = array_keys(ContentLanguages::others());

        if ($codes === []) {
            return ['seo_translations' => ['prohibited']];
        }

        return [
            'seo_translations' => ['nullable', 'array:'.implode(',', $codes)],
            'seo_translations.*' => ['array:title,description'],
            'seo_translations.*.title' => ['nullable', 'string', 'max:'.self::TITLE_LIMIT],
            'seo_translations.*.description' => ['nullable', 'string', 'max:'.self::DESCRIPTION_LIMIT],
        ];
    }

    /**
     * Field names for the error messages of rules().
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'seo_translations' => __('Other languages'),
            'seo_translations.*.title' => __('Title in search results'),
            'seo_translations.*.description' => __('Description in search results'),
        ];
    }

    /**
     * What the "Other languages" fields of an edit page show: the saved texts and the languages
     * that are ready. A page that is not saved yet has neither.
     *
     * @return array{values: array<string, array{title: string, description: string}>, ready: list<string>}
     */
    public static function form(Model|string $subject, string $mainTitle, string $mainDescription): array
    {
        if ($subject instanceof Model && ! $subject->exists) {
            return ['values' => [], 'ready' => []];
        }

        $values = self::all($subject);

        return ['values' => $values, 'ready' => self::readyLocales($subject, $mainTitle, $mainDescription, $values)];
    }

    /**
     * The name a page is saved under: "home", "product_group:12" or "product:7".
     */
    public static function subject(Model|string $subject): string
    {
        return match (true) {
            $subject instanceof ProductGroup => 'product_group:'.$subject->getKey(),
            $subject instanceof Product => 'product:'.$subject->getKey(),
            $subject instanceof Model => Str::snake(class_basename($subject)).':'.$subject->getKey(),
            default => mb_substr($subject, 0, 60),
        };
    }

    /**
     * The texts of one page in the other switched-on languages.
     *
     * @return array<string, array{title: string, description: string}> locale => texts
     */
    public static function all(Model|string $subject): array
    {
        return self::many([self::subject($subject)])[self::subject($subject)] ?? [];
    }

    /**
     * The texts of many pages in one go, for the sitemap.
     *
     * @param  list<string>  $subjects
     * @return array<string, array<string, array{title: string, description: string}>> subject => locale => texts
     */
    public static function many(array $subjects): array
    {
        $others = ContentLanguages::others();
        $subjects = array_values(array_unique(array_filter($subjects, 'is_string')));

        if ($others === [] || $subjects === []) {
            return [];
        }

        $result = [];

        try {
            foreach (array_chunk($subjects, 500) as $chunk) {
                $rows = SeoTranslation::query()->whereIn('subject', $chunk)->whereIn('locale', array_keys($others))
                    ->get(['subject', 'locale', 'title', 'description']);

                foreach ($rows as $row) {
                    $result[$row->subject][$row->locale] = ['title' => (string) $row->title, 'description' => (string) $row->description];
                }
            }
        } catch (QueryException) {
            return [];
        }

        return $result;
    }

    /**
     * The title written for a language, or null for the main language and when none is written.
     */
    public static function title(Model|string $subject, string $locale): ?string
    {
        return self::text($subject, $locale, 'title');
    }

    /**
     * The description written for a language, or null for the main language and when none is written.
     */
    public static function description(Model|string $subject, string $locale): ?string
    {
        return self::text($subject, $locale, 'description');
    }

    /**
     * Saves the texts of one page. Languages that are not switched on (or are the main language)
     * are left out; a language with both texts empty is removed.
     *
     * @param  array<string, array{title?: ?string, description?: ?string}>  $byLocale
     */
    public static function save(Model|string $subject, array $byLocale): void
    {
        $others = ContentLanguages::others();
        $key = self::subject($subject);

        try {
            foreach ($byLocale as $locale => $texts) {
                if (! is_string($locale) || ! isset($others[$locale]) || ! is_array($texts)) {
                    continue;
                }

                $title = self::clean($texts['title'] ?? null, self::TITLE_LIMIT);
                $description = self::clean($texts['description'] ?? null, self::DESCRIPTION_LIMIT);

                if ($title === null && $description === null) {
                    SeoTranslation::query()->where('subject', $key)->where('locale', $locale)->delete();
                } else {
                    SeoTranslation::query()->updateOrCreate(['subject' => $key, 'locale' => $locale], ['title' => $title, 'description' => $description]);
                }
            }
        } catch (QueryException $exception) {
            report($exception);
        }

        Sitemap::forget();
    }

    /**
     * Removes every language of a page, for example when its product is deleted.
     */
    public static function forget(Model|string $subject): void
    {
        try {
            SeoTranslation::query()->where('subject', self::subject($subject))->delete();
        } catch (QueryException) {
            // No table yet: nothing to remove.
        }
    }

    /**
     * The other languages a page is ready in: its title and description are written, and the
     * description is not a copy of the main one. Titles may match the main title, because brand
     * and product names often stay the same in every language.
     *
     * @param  array<string, array{title: string, description: string}>|null  $rows  From all() or many(), when already read.
     * @return list<string> Other languages that are ready, in the order of the language menu. The main language is not included.
     */
    public static function readyLocales(Model|string $subject, string $mainTitle, string $mainDescription, ?array $rows = null): array
    {
        $rows ??= self::all($subject);
        $main = self::comparable($mainDescription);
        $others = ContentLanguages::others();

        return array_values(array_filter(array_keys($others), function (string $locale) use ($rows, $main): bool {
            $title = trim((string) ($rows[$locale]['title'] ?? ''));
            $description = self::comparable((string) ($rows[$locale]['description'] ?? ''));

            return $title !== '' && $description !== '' && $description !== $main;
        }));
    }

    private static function text(Model|string $subject, string $locale, string $field): ?string
    {
        if ($locale === Locales::default()) {
            return null;
        }

        $text = trim(self::all($subject)[$locale][$field] ?? '');

        return $text === '' ? null : $text;
    }

    private static function clean(mixed $text, int $limit): ?string
    {
        $text = is_string($text) ? trim((string) preg_replace('/\s+/u', ' ', $text)) : '';
        $text = mb_substr($text, 0, $limit);

        return trim($text) === '' ? null : trim($text);
    }

    private static function comparable(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }
}
