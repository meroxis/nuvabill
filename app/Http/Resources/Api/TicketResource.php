<?php

namespace App\Http\Resources\Api;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Ticket
 */
class TicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'client_id' => $this->client_id,
            'department' => $this->whenLoaded('department', fn () => $this->department->name),
            'subject' => $this->subject,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'last_reply_at' => $this->last_reply_at?->toIso8601String(),
            'replies' => $this->whenLoaded('replies', fn () => $this->replies->map(fn ($reply): array => [
                'id' => $reply->id,
                'from' => $reply->isFromStaff() ? 'staff' : 'client',
                'author' => $reply->authorName(),
                'message' => $reply->message,
                'created_at' => $reply->created_at?->toIso8601String(),
            ])),
        ];
    }
}
