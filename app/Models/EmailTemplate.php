<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An editable email. The subject and body use {{ placeholder }} tags, for example {{ client.first_name }}.
 * The main text is in English; translations give the same email in other languages.
 */
class EmailTemplate extends Model
{
    protected $fillable = ['key', 'name', 'subject', 'body', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EmailTemplateTranslation, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(EmailTemplateTranslation::class);
    }

    /**
     * The subject and body in a language, falling back to the main text for anything not translated.
     *
     * @return array{0: string, 1: string}
     */
    public function textFor(string $locale): array
    {
        $translation = $locale === 'en' ? null : $this->translations->firstWhere('locale', $locale);

        return [
            filled($translation?->subject) ? (string) $translation->subject : $this->subject,
            filled($translation?->body) ? (string) $translation->body : $this->body,
        ];
    }
}
