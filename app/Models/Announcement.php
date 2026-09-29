<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Database\Factories\AnnouncementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * News for clients and visitors, for example new plans or price changes. The text is Markdown.
 * An announcement dated in the future shows from that time.
 */
class Announcement extends Model
{
    /** @use HasFactory<AnnouncementFactory> */
    use HasFactory;

    use Translatable;

    public const TRANSLATED_FIELDS = ['title' => 'title', 'body' => 'body'];

    protected $fillable = ['title', 'slug', 'body', 'is_published', 'published_at'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_published', true)->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('published_at')->orderByDesc('id');
    }

    public function isPublic(): bool
    {
        return $this->is_published && $this->published_at !== null && $this->published_at->lte(now());
    }

    public function html(): string
    {
        return Str::markdown($this->localized('body'), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    public function excerpt(int $length = 155): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(Str::markdown($this->localized('body'), ['html_input' => 'strip']))));

        return Str::limit(html_entity_decode($text, ENT_QUOTES | ENT_HTML5), $length, '…', preserveWords: true);
    }
}
