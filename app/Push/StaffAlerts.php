<?php

namespace App\Push;

use App\Jobs\SendStaffPush;
use App\Models\Admin;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Demo;
use Closure;
use Illuminate\Support\Str;

/**
 * Push alerts on staff phones: new orders, payments, new tickets and client replies. Each staff
 * member chooses which they want, and only gets what their role may see. The alert text is
 * written in each staff member's own language when it is sent.
 */
class StaffAlerts
{
    public function orderPlaced(Order $order): void
    {
        $order->loadMissing('client');

        $this->notify('orders', [
            'number' => (string) $order->number,
            'client' => (string) $order->client?->name,
            'total' => money((int) $order->total, (string) $order->currency),
            'url' => route('admin.orders.show', $order),
            'tag' => 'order-'.$order->id,
        ]);
    }

    public function invoicePaid(Invoice $invoice): void
    {
        $invoice->loadMissing('client');
        $currency = (string) $invoice->currency;
        $total = (int) $invoice->total;

        $this->notify('payments', [
            'number' => $invoice->displayNumber(),
            'client' => (string) $invoice->client?->name,
            'total' => money($total, $currency),
            'url' => route('admin.invoices.show', $invoice),
            'tag' => 'invoice-'.$invoice->id,
        ], fn (Admin $admin): bool => $currency !== (string) setting('billing.currency') || $total >= $admin->pushAlerts()['payments_over']);
    }

    public function ticketOpened(Ticket $ticket): void
    {
        $ticket->loadMissing('client');

        $this->notify('tickets', [
            'number' => (string) $ticket->number,
            'client' => (string) $ticket->client?->name,
            'text' => Str::limit((string) $ticket->subject, 120),
            'url' => route('admin.tickets.show', $ticket),
            'tag' => 'ticket-'.$ticket->id,
        ]);
    }

    public function clientReplied(Ticket $ticket, TicketReply $reply): void
    {
        $ticket->loadMissing('client');

        // A ticket someone took over only alerts them.
        $this->notify('replies', [
            'number' => (string) $ticket->number,
            'client' => (string) $ticket->client?->name,
            'text' => Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $reply->message))), 120),
            'url' => route('admin.tickets.show', $ticket),
            'tag' => 'ticket-'.$ticket->id,
        ], fn (Admin $admin): bool => $ticket->assigned_admin_id === null || $ticket->assigned_admin_id === $admin->id);
    }

    /**
     * The alert as the phone shows it, in the staff member's language.
     *
     * @param  array<string, string>  $data
     * @return array{title: string, body: string, url: string, tag: string}
     */
    public static function message(string $kind, array $data, string $locale): array
    {
        [$title, $body] = match ($kind) {
            'orders' => [__('New order #:number', $data, $locale), $data['client'].' · '.$data['total']],
            'payments' => [__('Payment received: :total', $data, $locale), __('Invoice :number', $data, $locale).' · '.$data['client']],
            'tickets' => [__('New ticket #:number', $data, $locale), $data['client'].': '.$data['text']],
            'replies' => [__('Reply on ticket #:number', $data, $locale), $data['client'].': '.$data['text']],
            default => [(string) setting('company.name'), __('Alerts work on this device.', [], $locale)],
        };

        return ['title' => $title, 'body' => $body, 'url' => $data['url'] ?? route('admin.today'), 'tag' => $data['tag'] ?? $kind];
    }

    /**
     * @param  array<string, string>  $data
     * @param  (Closure(Admin): bool)|null  $filter
     */
    private function notify(string $kind, array $data, ?Closure $filter = null): void
    {
        // The demo never calls other servers.
        if (Demo::isEnabled()) {
            return;
        }

        $admins = Admin::query()
            ->where('is_active', true)
            ->whereHas('pushSubscriptions')
            ->with('role')
            ->get()
            ->filter(fn (Admin $admin): bool => $admin->pushAlerts()[$kind] && ($filter === null || $filter($admin)));

        if ($admins->isNotEmpty()) {
            SendStaffPush::dispatch($admins->modelKeys(), $kind, $data)->afterCommit();
        }
    }
}
