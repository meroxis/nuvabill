<?php

namespace App\Http\Controllers\Admin;

use App\Ai\AiUnavailable;
use App\Ai\Claude;
use App\Ai\TicketAssistant;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Locales;
use App\Support\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        $filter = (string) $request->query('status', 'waiting');
        $department = $request->integer('department') ?: null;

        $tickets = Ticket::query()
            ->with('client', 'department', 'assignee')
            ->when($filter === 'waiting', fn ($query) => $query->whereIn('status', [TicketStatus::Open, TicketStatus::CustomerReply]))
            ->when(TicketStatus::tryFrom($filter), fn ($query, TicketStatus $status) => $query->where('status', $status))
            ->when($department, fn ($query) => $query->where('ticket_department_id', $department))
            ->orderByRaw("case when status in ('open', 'customer_reply') then 0 else 1 end")
            ->orderByDesc('last_reply_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.tickets.index', [
            'tickets' => $tickets,
            'filter' => $filter,
            'department' => $department,
            'departments' => TicketDepartment::query()->orderBy('sort_order')->pluck('name', 'id')->all(),
        ]);
    }

    public function show(Request $request, Ticket $ticket, Claude $claude, TicketAssistant $assistant): View
    {
        $ticket->load('client', 'department', 'service.product', 'replies.author', 'assignee');
        $admin = $request->user('admin');
        $aiReady = $claude->isReady() && $admin->hasPermission('ai.use');
        $summary = $ticket->ai_summary;

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'staff' => Admin::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
            'ai' => [
                'ready' => $aiReady,
                'drafts' => $aiReady && $claude->isOn('drafts'),
                'summaries' => $aiReady && $claude->isOn('summaries'),
                'translateIncoming' => $aiReady && $claude->isOn('translate'),
                'translateReply' => $aiReady && $assistant->canTranslateFor($ticket),
                'clientLanguage' => Locales::displayName(TicketAssistant::clientLanguage($ticket)),
                'facts' => $aiReady && $claude->isOn('drafts') ? $assistant->facts($ticket) : [],
                'summary' => is_array($summary) ? $summary : null,
                'summaryStale' => is_array($summary) && (int) ($summary['replies'] ?? 0) < $ticket->replies->count(),
                'setUp' => ! $claude->isReady() && $admin->hasPermission('settings.manage'),
            ],
        ]);
    }

    public function reply(Request $request, Ticket $ticket, TicketDesk $desk, TicketAssistant $assistant): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'status' => ['required', Rule::enum(TicketStatus::class)],
            'translate' => ['boolean'],
            'translation' => ['nullable', 'string', 'max:40000'],
            'translated_from' => ['nullable', 'string', 'max:20000'],
        ]);

        $admin = $request->user('admin');
        $message = $data['message'];
        $original = null;
        $language = null;

        // Sent in the client's language: the translation staff checked on the page, or a fresh one
        // when the reply changed after that.
        if ($request->boolean('translate') && $admin->hasPermission('ai.use') && $assistant->canTranslateFor($ticket)) {
            $same = filled($data['translation'] ?? null) && self::lines((string) ($data['translated_from'] ?? '')) === self::lines($message);

            try {
                $translated = $same
                    ? ['text' => (string) $data['translation'], 'language' => TicketAssistant::clientLanguage($ticket)]
                    : $assistant->translateReply($ticket, $message);
            } catch (AiUnavailable $exception) {
                return back()->withInput()->with('error', $exception->getMessage());
            }

            [$original, $message, $language] = [$message, $translated['text'], $translated['language']];
        }

        $desk->replyAsStaff($ticket, $admin, $message, TicketStatus::from($data['status']), $original, $language);

        return redirect()->route('admin.tickets.show', $ticket)->with('status', __('Reply sent to :email.', ['email' => $ticket->client->email]));
    }

    private static function lines(string $text): string
    {
        return trim(str_replace("\r\n", "\n", $text));
    }

    /**
     * Give the ticket to one staff member, or to nobody.
     */
    public function assign(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate(['admin' => ['nullable', Rule::exists('admins', 'id')->where('is_active', true)]]);
        $ticket->update(['assigned_admin_id' => $data['admin'] ?? null]);
        $ticket->load('assignee');

        return back()->with('status', $ticket->assignee ? __('Assigned to :name.', ['name' => $ticket->assignee->name]) : __('Nobody is assigned now.'));
    }

    public function close(Ticket $ticket, TicketDesk $desk): RedirectResponse
    {
        $desk->close($ticket);

        return redirect()->route('admin.tickets.index')->with('status', __('Ticket #:number closed.', ['number' => $ticket->number]));
    }
}
