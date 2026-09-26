<?php

namespace App\Http\Controllers\Client;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class TicketController extends Controller
{
    public function index(Request $request): View
    {
        return view('theme::client.tickets.index', [
            'tickets' => $request->user('web')->tickets()->with('department')->latest('last_reply_at')->paginate(20),
        ]);
    }

    public function create(Request $request): View
    {
        return view('theme::client.tickets.create', [
            'departments' => TicketDepartment::query()->visible()->orderBy('sort_order')->get(),
            'services' => $request->user('web')->services()->with('product')->latest('id')->get(),
        ]);
    }

    public function store(Request $request, TicketDesk $desk): RedirectResponse
    {
        $client = $request->user('web');

        $data = $request->validate([
            'department_id' => ['required', Rule::exists('ticket_departments', 'id')->where('is_visible', true)],
            'service_id' => ['nullable', Rule::exists('services', 'id')->where('client_id', $client->id)],
            'priority' => ['required', Rule::enum(TicketPriority::class)],
            'subject' => ['required', 'string', 'max:190'],
            'message' => ['required', 'string', 'max:20000'],
        ]);

        $ticket = $desk->open(
            $client,
            TicketDepartment::findOrFail($data['department_id']),
            $data['subject'],
            $data['message'],
            TicketPriority::from($data['priority']),
            isset($data['service_id']) ? $client->services()->find($data['service_id']) : null,
        );

        return redirect()->route('client.tickets.show', $ticket)->with('status', __('Ticket opened. We will reply by email and here.'));
    }

    public function show(Request $request, Ticket $ticket): View
    {
        $this->authorizeOwner($request, $ticket);

        $ticket->load('department', 'replies.author', 'service.product');

        return view('theme::client.tickets.show', ['ticket' => $ticket]);
    }

    public function reply(Request $request, Ticket $ticket, TicketDesk $desk): RedirectResponse
    {
        $this->authorizeOwner($request, $ticket);

        $data = $request->validate(['message' => ['required', 'string', 'max:20000']]);
        $desk->replyAsClient($ticket, $request->user('web'), $data['message']);

        return redirect()->route('client.tickets.show', $ticket)->with('status', __('Reply sent.'));
    }

    public function close(Request $request, Ticket $ticket, TicketDesk $desk): RedirectResponse
    {
        $this->authorizeOwner($request, $ticket);

        if ($ticket->status !== TicketStatus::Closed) {
            $desk->close($ticket);
        }

        return redirect()->route('client.tickets.index')->with('status', __('Ticket closed. You can still reply to open it again.'));
    }

    private function authorizeOwner(Request $request, Ticket $ticket): void
    {
        abort_unless($ticket->client_id === $request->user('web')->id, 404);
    }
}
