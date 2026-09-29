<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One check of a server: whether it answered, and how fast. Kept 90 days for the uptime numbers.
 */
class ServerCheck extends Model
{
    public $timestamps = false;

    protected $fillable = ['server_id', 'is_up', 'response_ms', 'checked_at'];

    protected function casts(): array
    {
        return [
            'is_up' => 'boolean',
            'response_ms' => 'integer',
            'checked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
