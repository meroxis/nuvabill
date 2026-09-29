<?php

namespace App\Http\Controllers\Admin;

use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Countries;
use App\Support\CsvExport;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV files for accountants: invoices, payments and refunds, credit notes and clients, for a date
 * range. Every download is written to the activity log.
 */
class ExportController extends Controller
{
    public const TYPES = ['invoices', 'payments', 'credit-notes', 'clients'];

    public function index(Request $request): View
    {
        return view('admin.exports.index', [
            'types' => $this->allowedTypes($request),
            'from' => today()->startOfMonth()->subMonth()->toDateString(),
            'to' => today()->startOfMonth()->subDay()->toDateString(),
        ]);
    }

    public function download(Request $request): StreamedResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->allowedTypes($request)))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $from = filled($data['from'] ?? null) ? CarbonImmutable::parse($data['from'])->startOfDay() : null;
        $to = filled($data['to'] ?? null) ? CarbonImmutable::parse($data['to'])->endOfDay() : null;
        $range = collect([$from?->toDateString(), $to?->toDateString()])->filter()->implode('_to_') ?: 'all';

        Activity::log('export.downloaded', "Exported {$data['type']} ({$range})");

        return match ($data['type']) {
            'invoices' => CsvExport::download("invoices-{$range}.csv", [
                __('Number'), __('Client ID'), __('Client'), __('Company'), __('Tax ID'), __('Country'), __('Status'), __('Currency'),
                __('Subtotal'), __('Tax'), __('Tax name'), __('Total'), __('Paid'), __('Balance'), __('Invoice date'), __('Due date'), __('Paid on'),
            ], $this->invoices($from, $to)),
            'payments' => CsvExport::download("payments-{$range}.csv", [
                __('ID'), __('Date'), __('Type'), __('Invoice'), __('Client ID'), __('Client'), __('Paid with'), __('Reference'), __('Amount'), __('Fee'), __('Currency'),
            ], $this->payments($from, $to)),
            'credit-notes' => CsvExport::download("credit-notes-{$range}.csv", [
                __('Number'), __('Date'), __('Invoice'), __('Client ID'), __('Client'), __('Currency'), __('Subtotal'), __('Tax'), __('Total'), __('Money'), __('Reason'),
            ], $this->creditNotes($from, $to)),
            default => CsvExport::download("clients-{$range}.csv", [
                __('ID'), __('First name'), __('Last name'), __('Company'), __('Email'), __('Phone'), __('Address'), __('Address line 2'), __('City'),
                __('State'), __('Postcode'), __('Country'), __('Tax ID'), __('Currency'), __('Status'), __('Wallet'), __('Created'),
            ], $this->clients($from, $to)),
        };
    }

    /**
     * @return array<string, string>
     */
    private function allowedTypes(Request $request): array
    {
        $admin = $request->user('admin');

        return array_filter([
            'invoices' => __('Invoices'),
            'payments' => __('Payments and refunds'),
            'credit-notes' => __('Credit notes'),
            'clients' => $admin->hasPermission('clients.manage') ? __('Clients') : null,
        ]);
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function invoices(?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $query = Invoice::query()->with('client')->where('status', '!=', InvoiceStatus::Draft)
            ->when($from, fn ($query) => $query->whereDate('issued_at', '>=', $from))
            ->when($to, fn ($query) => $query->whereDate('issued_at', '<=', $to));

        foreach ($query->lazyById(500) as $invoice) {
            yield [
                $invoice->displayNumber(), $invoice->client_id, $invoice->client?->name, $invoice->client?->company_name, $invoice->client?->tax_id,
                Countries::name($invoice->client?->country), $invoice->status->label(), $invoice->currency,
                Money::toDecimal($invoice->subtotal), Money::toDecimal($invoice->tax), $invoice->tax_name, Money::toDecimal($invoice->total),
                Money::toDecimal($invoice->amount_paid), Money::toDecimal($invoice->balance()),
                $invoice->issued_at?->toDateString(), $invoice->due_at?->toDateString(), $invoice->paid_at?->toDateString(),
            ];
        }
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function payments(?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $query = Transaction::query()->with(['client', 'invoice'])
            ->when($from, fn ($query) => $query->where('paid_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('paid_at', '<=', $to));

        foreach ($query->lazyById(500) as $transaction) {
            yield [
                $transaction->id, $transaction->paid_at?->toDateTimeString(), $transaction->type === 'refund' ? __('Refund') : __('Payment'),
                $transaction->invoice?->displayNumber(), $transaction->client_id, $transaction->client?->name, $transaction->gatewayLabel(),
                $transaction->reference, Money::toDecimal((int) $transaction->amount), Money::toDecimal((int) $transaction->fee), $transaction->currency,
            ];
        }
    }

    /**
     * @return iterable<int, list<mixed>>
     */
    private function creditNotes(?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $query = CreditNote::query()->with(['client', 'invoice'])
            ->when($from, fn ($query) => $query->where('issued_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('issued_at', '<=', $to));

        foreach ($query->lazyById(500) as $creditNote) {
            yield [
                $creditNote->displayNumber(), $creditNote->issued_at->toDateString(), $creditNote->invoice?->displayNumber(), $creditNote->client_id,
                $creditNote->client?->name, $creditNote->currency, Money::toDecimal($creditNote->subtotal), Money::toDecimal($creditNote->tax),
                Money::toDecimal($creditNote->total), $creditNote->methodLabel(), $creditNote->reason,
            ];
        }
    }

    /**
     * Clients who signed up in the range, or everyone without dates.
     *
     * @return iterable<int, list<mixed>>
     */
    private function clients(?CarbonImmutable $from, ?CarbonImmutable $to): iterable
    {
        $query = Client::query()
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to));

        foreach ($query->lazyById(500) as $client) {
            yield [
                $client->id, $client->first_name, $client->last_name, $client->company_name, $client->isErased() ? '' : $client->email, $client->phone,
                $client->address_1, $client->address_2, $client->city, $client->state, $client->postcode, Countries::name($client->country), $client->tax_id,
                $client->currency, $client->status->label(), Money::toDecimal((int) $client->credit),
                $client->created_at?->toDateString(),
            ];
        }
    }
}
