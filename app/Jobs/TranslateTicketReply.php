<?php

namespace App\Jobs;

use App\Ai\AiUnavailable;
use App\Ai\TicketAssistant;
use App\Models\TicketReply;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Translates a client's ticket message into the staff language in the background, so staff see
 * it translated when they open the ticket. When AI help cannot answer, staff can still press
 * Translate on the message later.
 */
class TranslateTicketReply implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $replyId) {}

    public function handle(TicketAssistant $assistant): void
    {
        $reply = TicketReply::query()->with('ticket')->find($this->replyId);

        if ($reply === null || $reply->language !== null) {
            return;
        }

        try {
            $assistant->translateIncoming($reply);
        } catch (AiUnavailable) {
            // The Translate button on the message tells staff why.
        }
    }
}
