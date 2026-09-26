<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Google, GitHub or Facebook account a client signs in with.
 */
class SocialAccount extends Model
{
    protected $table = 'client_social_accounts';

    protected $fillable = ['client_id', 'provider', 'provider_user_id', 'email', 'last_used_at'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
