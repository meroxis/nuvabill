<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiUnavailable;
use App\Ai\TicketAssistant;
use App\Enums\TicketPriority;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Demo;
use App\Support\Locales;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI help on the ticket page: the summary, reply drafts, and translation both ways. Every answer
 * is JSON for the page; staff always read and send replies themselves.
 */
class TicketAiController extends Controller
{
    public function __construct(private TicketAssistant $assistant) {}

    public function summary(Ticket $ticket): JsonResponse
    {
        return $this->answer(function () use ($ticket): array {
            $summary = $this->assistant->summarize($ticket);

            return $summary + ['priority_label' => TicketPriority::from($summary['priority'])->label()];
        });
    }

    public function draft(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'instruction' => ['nullable', 'string', 'max:1000'],
            'tone' => ['nullable', Rule::in(array_keys(TicketAssistant::TONES))],
            'current' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->answer(fn (): array => $this->assistant->draft($ticket, $request->user('admin'), $data['instruction'] ?? null, $data['tone'] ?? null, $data['current'] ?? null));
    }

    /**
     * The staff member's reply in the client's language, to check before sending.
     */
    public function translate(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:20000']]);

        return $this->answer(function () use ($ticket, $data): array {
            $translation = $this->assistant->translateReply($ticket, $data['text']);

            return $translation + ['language_name' => Locales::displayName($translation['language'])];
        });
    }

    /**
     * Translate one client message for staff, for messages the background translation skipped.
     */
    public function translateMessage(Ticket $ticket, TicketReply $reply): JsonResponse
    {
        return $this->answer(function () use ($reply): array {
            $reply = $this->assistant->translateIncoming($reply);

            return [
                'translation' => $reply->translation,
                'language' => $reply->language,
                'language_name' => isset(Locales::ALL[(string) $reply->language]) ? Locales::displayName((string) $reply->language) : null,
                'notice' => Demo::isEnabled() ? TicketAssistant::demoNotice() : ($reply->translation === null ? __('This message is already in your team’s language.') : null),
            ];
        });
    }

    /**
     * @param  Closure(): array<string, mixed>  $work
     */
    private function answer(Closure $work): JsonResponse
    {
        try {
            return response()->json($work());
        } catch (AiUnavailable $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
