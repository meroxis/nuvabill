<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stored state (enabled flag and settings) for an extension found in the extensions folder.
 */
class Extension extends Model
{
    protected $fillable = ['slug', 'type', 'is_enabled', 'settings', 'version'];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'settings' => 'encrypted:array',
        ];
    }
}
