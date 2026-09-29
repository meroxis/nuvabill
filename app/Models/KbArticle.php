<?php

namespace App\Models;

use App\Models\Concerns\Translatable;
use Database\Factories\KbArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A knowledge base article. The text is Markdown; HTML in it is shown as text.
 */
class KbArticle extends Model
{
    /** @use HasFactory<KbArticleFactory> */
    use HasFactory;

    use Translatable;

    public const TRANSLATED_FIELDS = ['title' => 'title', 'body' => 'body'];

    protected $fillable = ['kb_category_id', 'title', 'slug', 'body', 'is_published', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'sort_order' => 'integer',
            'helpful_yes' => 'integer',
            'helpful_no' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<KbCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(KbCategory::class, 'kb_category_id');
    }

    /**
     * Published articles in a category visitors can see.
     *
     * @param  Builder<self>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('is_published', true)->whereHas('category', fn (Builder $category) => $category->where('is_visible', true));
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('title');
    }

    public function url(): string
    {
        return route('kb.article', [$this->category->slug, $this->slug]);
    }

    /**
     * The article as HTML, in the page's language.
     */
    public function html(): string
    {
        return Str::markdown($this->localized('body'), ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /**
     * The first words of the text, for lists and the search engine description.
     */
    public function excerpt(int $length = 155): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(Str::markdown($this->localized('body'), ['html_input' => 'strip']))));

        return Str::limit(html_entity_decode($text, ENT_QUOTES | ENT_HTML5), $length, '…', preserveWords: true);
    }

    /**
     * Published articles that have every word, best matches first: words in the title count more
     * than words in the text. Looks in the main text and in the visitor's language.
     *
     * @return Collection<int, self>
     */
    public static function search(string $query, int $limit = 20): Collection
    {
        $words = collect(preg_split('/\s+/u', mb_strtolower(str_replace(['%', '_', '\\'], ' ', mb_substr($query, 0, 200)))))
            ->filter(fn (string $word): bool => mb_strlen($word) >= 2)
            ->unique()
            ->take(6)
            ->values();

        if ($words->isEmpty()) {
            return collect();
        }

        $locale = app()->getLocale();
        $articles = self::query()->public()->withTranslation()->with('category')
            ->where(function (Builder $query) use ($words, $locale): void {
                foreach ($words as $word) {
                    $query->where(fn (Builder $match) => $match
                        ->where('title', 'like', "%{$word}%")
                        ->orWhere('body', 'like', "%{$word}%")
                        ->orWhereHas('translations', fn (Builder $translation) => $translation->where('locale', $locale)
                            ->where(fn (Builder $text) => $text->where('title', 'like', "%{$word}%")->orWhere('body', 'like', "%{$word}%"))));
                }
            })
            ->limit(100)
            ->get();

        return $articles
            ->sortByDesc(function (self $article) use ($words): int {
                $title = mb_strtolower($article->localized('title'));

                return $words->sum(fn (string $word): int => str_contains($title, $word) ? 3 : 1);
            })
            ->take($limit)
            ->values();
    }
}
