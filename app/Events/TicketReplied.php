<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Someone replied to a ticket. Check $reply->author_type to see if it was the client or staff.
 */
class TicketReplied
{
    use Dispatchable;

    public function __construct(public Ticket $ticket, public TicketReply $reply) {}
}
