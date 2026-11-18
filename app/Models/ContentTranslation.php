<?php

namespace App\Models;

use App\Support\ContentLanguages;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A knowledge base article, category or announcement in another language. Empty fields use the
 * main text.
 */
class ContentTranslation extends Model
{
    protected $fillable = ['locale', 'title', 'body'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function translatable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Only what readyLocales() needs, for long lists such as the sitemap: the other switched-on
     * languages, their titles, and whether a text is written ("has_body"), without the texts.
     * A text of only spaces and line breaks counts as empty, as it does in readyLocales().
     *
     * @param  Builder<static>|Relation<static, Model, mixed>  $query
     */
    public static function onlyReadiness(Builder|Relation $query): void
    {
        $query->whereIn('locale', array_keys(ContentLanguages::others()))
            ->select(['id', 'translatable_type', 'translatable_id', 'locale', 'title'])
            ->selectRaw(
                "case when body is null then 0 when replace(replace(replace(replace(replace(replace(body, ?, ''), ?, ''), ?, ''), ?, ''), ?, ''), ?, '') = '' then 0 else 1 end as has_body",
                [' ', "\t", "\n", "\r", "\0", "\x0B"],
            );
    }
}
