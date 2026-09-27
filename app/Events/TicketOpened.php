<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client opened a support ticket.
 */
class TicketOpened
{
    use Dispatchable;

    public function __construct(public Ticket $ticket, public TicketReply $message) {}
}
