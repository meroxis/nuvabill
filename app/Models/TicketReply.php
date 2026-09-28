<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class TicketReply extends Model
{
    protected $fillable = ['ticket_id', 'author_type', 'author_id', 'message', 'ip_address'];

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * The client or staff member who wrote the reply.
     *
     * @return MorphTo<Model, $this>
     */
    public function author(): MorphTo
    {
        return $this->morphTo();
    }

    public function isFromStaff(): bool
    {
        return $this->author_type === 'admin';
    }

    public function authorName(): string
    {
        // Messages written by an automation are staff messages without a staff member.
        if ($this->author_type === 'admin' && $this->author_id === null) {
            return (string) setting('company.name');
        }

        return $this->author?->name ?? __('Deleted user');
    }
}
