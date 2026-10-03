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

    /**
     * The queue for AI work. The worker takes it only when the default queue is empty, so setting
     * up services and registering domains never waits behind translations.
     */
    public const QUEUE = 'ai';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $replyId)
    {
        $this->onQueue(self::QUEUE);
    }

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
