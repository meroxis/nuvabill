<?php

namespace App\Http\Controllers\Api;

use App\Enums\TicketStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\TicketResource;
use App\Models\Ticket;
use App\Support\TicketDesk;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $tickets = Ticket::query()
            ->with('department')
            ->when($request->integer('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('last_reply_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return TicketResource::collection($tickets);
    }

    public function show(Ticket $ticket): TicketResource
    {
        return new TicketResource($ticket->load('department', 'replies.author'));
    }

    /**
     * Reply as the staff member who owns the key. The client gets the usual email.
     */
    public function reply(Request $request, Ticket $ticket, TicketDesk $desk): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:20000'],
            'status' => ['nullable', Rule::in([TicketStatus::Answered->value, TicketStatus::Closed->value])],
        ]);

        $desk->replyAsStaff($ticket, $request->user('admin'), $data['message'], TicketStatus::from($data['status'] ?? TicketStatus::Answered->value));

        return (new TicketResource($ticket->fresh()->load('department', 'replies.author')))->response()->setStatusCode(201);
    }
}
