<?php

namespace App\Models;

use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;

/**
 * A single key/value setting. Read settings through {@see Settings}, which caches them.
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value'];
}
