<?php

namespace App\Models\Concerns;

use App\Models\ContentTranslation;
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
