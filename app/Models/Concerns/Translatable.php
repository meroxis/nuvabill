<?php

namespace App\Models\Concerns;

use App\Models\ContentTranslation;
use App\Support\ContentLanguages;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Content staff write in the site's default language, with a version for each other language.
 * Visitors see their language where it is filled in, and the main text everywhere else.
 *
 * The model lists which of its columns are the title and the text in TRANSLATED_FIELDS.
 */
trait Translatable
{
    /**
     * @return MorphMany<ContentTranslation, $this>
     */
    public function translations(): MorphMany
    {
        return $this->morphMany(ContentTranslation::class, 'translatable');
    }

    /**
     * Every language, for lists that need them all at once, such as the sitemap. Eager-load this
     * one (not "translations", which pages load for one language only) before readyLocales().
     *
     * @return MorphMany<ContentTranslation, $this>
     */
    public function allTranslations(): MorphMany
    {
        return $this->morphMany(ContentTranslation::class, 'translatable');
    }

    /**
     * Other switched-on languages whose translation is complete: a title, and a text when
     * $needsBody. Uses allTranslations when it is loaded, else asks the database.
     *
     * @return list<string> In the order of the language menu; the main language is not included.
     */
    public function readyLocales(bool $needsBody = true): array
    {
        $others = array_keys(ContentLanguages::others());

        if ($others === []) {
            return [];
        }

        $rows = $this->relationLoaded('allTranslations')
            ? $this->getRelation('allTranslations')
            : $this->translations()->whereIn('locale', $others)->get(['locale', 'title', 'body']);

        $complete = $rows
            ->filter(fn (ContentTranslation $row): bool => filled($row->title) && (! $needsBody || filled($row->body)))
            ->pluck('locale')
            ->all();

        return array_values(array_filter($others, fn (string $locale): bool => in_array($locale, $complete, true)));
    }

    /**
     * Loads only the translation for the language the page is shown in.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWithTranslation(Builder $query, ?string $locale = null): void
    {
        $locale ??= app()->getLocale();

        $query->with(['translations' => fn ($translations) => $translations->where('locale', $locale)]);
    }

    /**
     * The title or the text ("title" or "body") in the page's language.
     */
    public function localized(string $field, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $main = (string) $this->getAttribute(static::TRANSLATED_FIELDS[$field]);

        if ($locale === Locales::default()) {
            return $main;
        }

        $translated = $this->translationFor($locale)?->{$field};

        return filled($translated) ? (string) $translated : $main;
    }

    public function translationFor(string $locale): ?ContentTranslation
    {
        return $this->translations->firstWhere('locale', $locale);
    }

    /**
     * Saves one language. Both fields empty removes it.
     */
    public function saveTranslation(string $locale, ?string $title, ?string $body): void
    {
        if (blank($title) && blank($body)) {
            $this->translations()->where('locale', $locale)->delete();
        } else {
            $this->translations()->updateOrCreate(['locale' => $locale], ['title' => $title, 'body' => $body]);
        }

        $this->unsetRelation('translations');
    }

    /**
     * The languages a translation is written in, for the language chips in the admin area.
     *
     * @return list<string>
     */
    public function translatedLocales(): array
    {
        return $this->translations
            ->filter(fn (ContentTranslation $row): bool => filled($row->title) || filled($row->body))
            ->pluck('locale')
            ->values()
            ->all();
    }

    protected static function bootTranslatable(): void
    {
        static::deleting(function ($model): void {
            $model->translations()->delete();
        });
    }
}
