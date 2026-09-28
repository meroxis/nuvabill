<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Models\Admin;
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

    public function show(Ticket $ticket): View
    {
        $ticket->load('client', 'department', 'service.product', 'replies.author', 'assignee');

        return view('admin.tickets.show', [
            'ticket' => $ticket,
            'staff' => Admin::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function reply(Request $request, Ticket $ticket, TicketDesk $desk): RedirectResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'status' => ['required', Rule::enum(TicketStatus::class)],
        ]);

        $desk->replyAsStaff($ticket, $request->user('admin'), $data['message'], TicketStatus::from($data['status']));

        return redirect()->route('admin.tickets.show', $ticket)->with('status', __('Reply sent to :email.', ['email' => $ticket->client->email]));
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
