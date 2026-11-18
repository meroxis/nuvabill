<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * An address people tried to open that showed "page not found", with how often and when. Only the
 * address is kept: no IP address, referrer or browser.
 *
 * @property string $path
 * @property int $hits
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $last_seen_at
 */
class SeoMissingPage extends Model
{
    public $timestamps = false;

    protected $fillable = ['path', 'hits', 'first_seen_at', 'last_seen_at'];

    protected function casts(): array
    {
        return ['hits' => 'integer', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime'];
    }
}
