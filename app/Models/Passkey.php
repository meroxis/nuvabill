<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A passkey of a staff member or client. The credential ID is kept base64url encoded, and
 * credential_hash (SHA-256 of the raw ID) finds it quickly at sign-in.
 */
class Passkey extends Model
{
    protected $fillable = ['owner_type', 'owner_id', 'name', 'credential_id', 'credential_hash', 'public_key', 'algorithm', 'sign_count', 'transports', 'last_used_at'];

    protected $hidden = ['public_key'];

    protected function casts(): array
    {
        return [
            'algorithm' => 'integer',
            'sign_count' => 'integer',
            'transports' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function owner(): MorphTo
    {
        return $this->morphTo();
    }
}
