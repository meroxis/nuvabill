<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The search title and description of one page in one of the site's other languages. Use
 * App\Seo\SeoTranslations to read and save them.
 *
 * @property string $subject
 * @property string $locale
 * @property string|null $title
 * @property string|null $description
 */
class SeoTranslation extends Model
{
    protected $fillable = ['subject', 'locale', 'title', 'description'];
}
