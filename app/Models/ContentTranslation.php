<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
}
