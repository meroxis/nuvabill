<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An editable email. The subject and body use {{ placeholder }} tags, for example {{ client.first_name }}.
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
}
