<?php

namespace App\Support;

use App\Billing\SavedMethods;
use App\Enums\ClientStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Privacy tools for the GDPR and similar laws: everything Nuvabill keeps about a client, as one
 * file they can take with them, and erasing it when they ask. Invoices, payments and credit notes
 * stay, because tax law requires them; the name, company, address and tax ID they show stay with
 * them. Everything else goes.
 */
class ClientPrivacy
{
    public function __construct(private SavedMethods $savedMethods) {}

    /**
     * Everything about the client, for a JSON file. Staff notes are only included for staff.
     *
     * @return array<string, mixed>
     */
    public function export(Client $client, bool $forStaff = false): array
    {
        $client->load([
            'services.product', 'domains', 'invoices.items', 'invoices.transactions', 'invoices.creditNotes', 'quotes.items',
            'orders', 'tickets.replies', 'tickets.department', 'creditTransactions', 'paymentMethods', 'socialAccounts', 'passkeys',
        ]);
        $date = fn ($value): ?string => $value?->toIso8601String();
        $amount = fn (?int $minor): string => Money::toDecimal((int) $minor);

        return [
            'exported_at' => now()->toIso8601String(),
            'company' => setting('company.name'),
            'profile' => array_filter([
                'id' => $client->id,
                'first_name' => $client->first_name,
                'last_name' => $client->last_name,
                'company_name' => $client->company_name,
                'email' => $client->email,
                'phone' => $client->phone,
                'address_1' => $client->address_1,
                'address_2' => $client->address_2,
                'city' => $client->city,
                'state' => $client->state,
                'postcode' => $client->postcode,
                'country' => $client->country,
                'tax_id' => $client->tax_id,
                'currency' => $client->currency,
                'language' => $client->language,
                'status' => $client->status->value,
                'wallet' => $amount($client->credit),
                'two_factor' => $client->hasTwoFactorEnabled(),
                'tags' => $client->tags,
                'staff_notes' => $forStaff ? $client->notes : null,
                'created_at' => $date($client->created_at),
            ], fn ($value): bool => $value !== null),
            'services' => $client->services->map(fn ($service): array => [
                'product' => $service->product?->name,
                'domain' => $service->domain,
                'username' => $service->username,
                'status' => $service->status->value,
                'billing_cycle' => $service->billing_cycle->value,
                'amount' => $amount($service->recurring_amount),
                'currency' => $service->currency,
                'registration_date' => $service->registration_date?->toDateString(),
                'next_due_date' => $service->next_due_date?->toDateString(),
            ])->all(),
            'domains' => $client->domains->map(fn ($domain): array => [
                'name' => $domain->name,
                'status' => $domain->status->value,
                'registered_at' => $domain->registered_at?->toDateString(),
                'expires_at' => $domain->expires_at?->toDateString(),
            ])->all(),
            'invoices' => $client->invoices->where('status', '!=', InvoiceStatus::Draft)->values()->map(fn (Invoice $invoice): array => [
                'number' => $invoice->displayNumber(),
                'status' => $invoice->status->value,
                'currency' => $invoice->currency,
                'total' => $amount($invoice->total),
                'tax' => $amount($invoice->tax),
                'issued_at' => $invoice->issued_at?->toDateString(),
                'due_at' => $invoice->due_at?->toDateString(),
                'paid_at' => $date($invoice->paid_at),
                'items' => $invoice->items->map(fn ($item): array => ['description' => $item->description, 'amount' => $amount($item->amount)])->all(),
                'payments' => $invoice->transactions->map(fn ($transaction): array => [
                    'type' => $transaction->type,
                    'amount' => $amount($transaction->amount),
                    'paid_with' => $transaction->gatewayLabel(),
                    'date' => $date($transaction->paid_at),
                ])->all(),
                'credit_notes' => $invoice->creditNotes->map(fn ($note): array => [
                    'number' => $note->displayNumber(),
                    'total' => $amount($note->total),
                    'date' => $date($note->issued_at),
                    'reason' => $note->reason,
                ])->all(),
            ])->all(),
            'quotes' => $client->quotes->map(fn ($quote): array => [
                'number' => $quote->displayNumber(),
                'subject' => $quote->subject,
                'status' => $quote->status->value,
                'total' => $amount($quote->total),
                'currency' => $quote->currency,
            ])->all(),
            'orders' => $client->orders->map(fn (Order $order): array => [
                'number' => $order->number,
                'status' => $order->status->value,
                'total' => $amount($order->total),
                'currency' => $order->currency,
                'ip_address' => $order->ip_address,
                'date' => $date($order->created_at),
            ])->all(),
            'wallet_history' => $client->creditTransactions->map(fn ($entry): array => [
                'amount' => $amount($entry->amount),
                'balance' => $amount($entry->balance),
                'description' => $entry->description,
                'date' => $date($entry->created_at),
            ])->all(),
            'tickets' => $client->tickets->map(fn (Ticket $ticket): array => [
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'department' => $ticket->department?->name,
                'status' => $ticket->status->value,
                'messages' => $ticket->replies->map(fn (TicketReply $reply): array => [
                    'from' => $reply->author_type === 'client' ? 'you' : 'staff',
                    'message' => $reply->original_message ?: $reply->message,
                    'ip_address' => $reply->author_type === 'client' ? $reply->ip_address : null,
                    'date' => $date($reply->created_at),
                ])->all(),
            ])->all(),
            'saved_payment_methods' => $client->paymentMethods->map(fn (PaymentMethod $method): array => [
                'name' => $method->label(),
                'expires' => $method->expiry(),
            ])->all(),
            'sign_in' => [
                'passkeys' => $client->passkeys->map(fn ($passkey): array => ['name' => $passkey->name, 'added' => $date($passkey->created_at)])->all(),
                'social_accounts' => $client->socialAccounts->map(fn ($account): array => ['provider' => $account->provider, 'email' => $account->email])->all(),
            ],
            'activity' => ActivityLog::query()->where('client_id', $client->id)->latest('id')->limit(1000)->get()
                ->map(fn (ActivityLog $entry): array => ['action' => $entry->action, 'description' => $entry->description, 'ip_address' => $entry->actor_type === 'client' ? $entry->ip_address : null, 'date' => $date($entry->created_at)])
                ->all(),
        ];
    }

    /**
     * What stops erasing now, in words for staff. Empty when the client can be erased.
     *
     * @return list<string>
     */
    public function blockers(Client $client): array
    {
        $blockers = [];

        if ($client->services()->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])->exists()) {
            $blockers[] = __('Terminate or cancel the client\'s services first.');
        }

        if ($client->domains()->whereIn('status', [DomainStatus::Active, DomainStatus::Pending, DomainStatus::PendingTransfer])->exists()) {
            $blockers[] = __('Cancel or transfer away the client\'s domains first.');
        }

        if ($client->invoices()->where('status', InvoiceStatus::Unpaid)->exists()) {
            $blockers[] = __('Cancel or settle the client\'s unpaid invoices first.');
        }

        if ($client->credit !== 0) {
            $blockers[] = __('Pay out or remove the money in the client\'s wallet first.');
        }

        return $blockers;
    }

    /**
     * Erase the client's personal data. They can no longer sign in.
     *
     * @throws InvalidArgumentException When something still needs the data (see blockers()).
     */
    public function erase(Client $client, Admin $admin): void
    {
        if ($client->isErased()) {
            return;
        }

        if (($blockers = $this->blockers($client)) !== []) {
            throw new InvalidArgumentException(implode(' ', $blockers));
        }

        // Saved cards and PayPal accounts are removed at the gateway too.
        foreach ($client->paymentMethods()->get() as $method) {
            rescue(fn () => $this->savedMethods->forget($method, $admin->name), report: false);
        }

        $keepForInvoices = $client->invoices()->where('status', '!=', InvoiceStatus::Draft)->exists() || $client->transactions()->exists();

        DB::transaction(function () use ($client, $keepForInvoices): void {
            $client->paymentMethods()->delete();
            $client->passkeys()->delete();
            $client->socialAccounts()->delete();
            DB::table('chat_links')->where('client_id', $client->id)->delete();

            foreach ($client->tickets()->pluck('id') as $ticketId) {
                TicketReply::query()->where('ticket_id', $ticketId)->delete();
            }

            $client->tickets()->delete();
            $client->invoices()->where('status', InvoiceStatus::Draft)->each(fn (Invoice $invoice) => $invoice->delete());
            Order::query()->where('client_id', $client->id)->update(['ip_address' => null, 'notes' => null]);
            ActivityLog::query()->where('client_id', $client->id)->update(['ip_address' => null]);
            ActivityLog::query()->where('actor_type', 'client')->where('actor_id', $client->id)->update(['ip_address' => null]);

            $fields = [
                'email' => 'erased-'.$client->id.'@erased.invalid',
                'password' => Hash::make(Str::random(64)),
                'phone' => null,
                'notes' => null,
                'tags' => null,
                'status' => ClientStatus::Closed,
                'remember_token' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_method' => null,
                'two_factor_confirmed_at' => null,
                'legacy_password' => null,
                'erased_at' => now(),
            ];

            if (! $keepForInvoices) {
                $fields += [
                    'first_name' => __('Erased'),
                    'last_name' => __('client #:id', ['id' => $client->id]),
                    'company_name' => null,
                    'address_1' => null,
                    'address_2' => null,
                    'city' => null,
                    'state' => null,
                    'postcode' => null,
                    'tax_id' => null,
                ];
            }

            $client->forceFill($fields)->save();
        });

        Activity::log('client.erased', "Personal data of client #{$client->id} erased", $client);
    }
}
