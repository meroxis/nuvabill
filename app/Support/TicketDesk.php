<?php

namespace App\Support;

use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Events\TicketOpened;
use App\Events\TicketReplied;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use Illuminate\Support\Facades\DB;

/**
 * Opens support tickets and records replies from clients and staff, with the matching emails.
 */
class TicketDesk
{
    public function __construct(private TemplateMailer $mailer) {}

    public function open(
        Client $client,
        TicketDepartment $department,
        string $subject,
        string $message,
        TicketPriority $priority = TicketPriority::Medium,
        ?Service $service = null,
    ): Ticket {
        $ticket = DB::transaction(function () use ($client, $department, $subject, $message, $priority, $service): Ticket {
            $ticket = Ticket::create([
                'number' => $this->uniqueNumber(),
                'client_id' => $client->id,
                'ticket_department_id' => $department->id,
                'service_id' => $service?->id,
                'subject' => $subject,
                'status' => TicketStatus::Open,
                'priority' => $priority,
                'last_reply_at' => now(),
            ]);

            $ticket->setRelation('firstMessage', $this->addReply($ticket, $client, $message));

            return $ticket;
        });

        $ticket->load('department', 'client');

        Activity::log('ticket.opened', "Ticket #{$ticket->number} opened: {$subject}", $ticket, $client);
        $this->mailer->send('ticket.opened', $client, TemplateMailer::ticketContext($ticket));
        $this->notifyStaff($ticket, 'admin.ticket_opened', $message);

        TicketOpened::dispatch($ticket, $ticket->getRelation('firstMessage'));

        return $ticket;
    }

    public function replyAsClient(Ticket $ticket, Client $client, string $message): TicketReply
    {
        $reply = $this->addReply($ticket, $client, $message);
        $ticket->update(['status' => TicketStatus::CustomerReply, 'last_reply_at' => now(), 'closed_at' => null]);

        $this->notifyStaff($ticket, 'admin.ticket_reply', $message);

        TicketReplied::dispatch($ticket, $reply);

        return $reply;
    }

    public function replyAsStaff(Ticket $ticket, Admin $admin, string $message, TicketStatus $status = TicketStatus::Answered): TicketReply
    {
        $reply = $this->addReply($ticket, $admin, $message);
        $ticket->update([
            'status' => $status,
            'last_reply_at' => now(),
            'closed_at' => $status === TicketStatus::Closed ? now() : null,
        ]);

        Activity::log('ticket.replied', "Replied to ticket #{$ticket->number}", $ticket);
        $this->mailer->send('ticket.reply', $ticket->client, TemplateMailer::ticketContext($ticket) + ['reply' => ['message' => $message, 'author' => $admin->name]]);

        TicketReplied::dispatch($ticket, $reply);

        return $reply;
    }

    public function close(Ticket $ticket): void
    {
        $ticket->update(['status' => TicketStatus::Closed, 'closed_at' => now()]);
        Activity::log('ticket.closed', "Ticket #{$ticket->number} closed", $ticket);
    }

    private function addReply(Ticket $ticket, Client|Admin $author, string $message): TicketReply
    {
        return $ticket->replies()->create([
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'message' => $message,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }

    private function notifyStaff(Ticket $ticket, string $template, string $message): void
    {
        $ticket->loadMissing('department', 'client');
        $email = $ticket->department->email ?: (string) setting('company.email');

        if ($email === '') {
            return;
        }

        $this->mailer->sendTo($template, $email, (string) setting('company.name'), TemplateMailer::ticketContext($ticket) + [
            'reply' => ['message' => $message, 'author' => $ticket->client->name],
            'admin_url' => route('admin.tickets.show', $ticket),
        ]);
    }

    private function uniqueNumber(): string
    {
        do {
            $number = (string) random_int(100000, 999999);
        } while (Ticket::query()->where('number', $number)->exists());

        return $number;
    }
}
