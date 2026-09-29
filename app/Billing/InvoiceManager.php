<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Creates invoices and keeps their totals and numbers correct.
 */
class InvoiceManager
{
    public function __construct(private Taxes $taxes) {}

    /**
     * Tax follows the client's tax rule at the time of the invoice; each line can set "taxed" itself.
     *
     * @param  list<array{type?: string, description: string, amount: int, taxed?: bool, service_id?: int|null, domain_id?: int|null, period_start?: CarbonInterface|string|null, period_end?: CarbonInterface|string|null, billing_key?: string|null}>  $items
     *
     * @throws UniqueConstraintViolationException When a line's billing period is already invoiced.
     */
    public function create(
        Client $client,
        array $items,
        ?CarbonInterface $dueAt = null,
        InvoiceStatus $status = InvoiceStatus::Unpaid,
        ?string $notes = null,
        ?string $currency = null,
    ): Invoice {
        return DB::transaction(function () use ($client, $items, $dueAt, $status, $notes, $currency): Invoice {
            $rule = $this->taxes->ruleFor($client);

            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => $status,
                'currency' => $currency ?? $client->currency,
                'issued_at' => today(),
                'due_at' => $dueAt ?? today(),
                'notes' => $notes,
                'tax_name' => $rule?->name,
                'tax_rate' => $rule?->rate,
                'tax_inclusive' => $rule !== null && $this->taxes->inclusive(),
            ]);

            foreach ($items as $item) {
                $invoice->items()->create([
                    'type' => $item['type'] ?? 'manual',
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'taxed' => $rule !== null && $this->taxes->isTaxable($item),
                    'service_id' => $item['service_id'] ?? null,
                    'domain_id' => $item['domain_id'] ?? null,
                    'period_start' => $item['period_start'] ?? null,
                    'period_end' => $item['period_end'] ?? null,
                    // Unique in the database: a second invoice for the same period fails and is rolled back.
                    'billing_key' => $item['billing_key'] ?? null,
                ]);
            }

            $invoice->recalculate();

            if ($status !== InvoiceStatus::Draft) {
                $invoice->number = $this->numberFor($invoice);
            }

            $invoice->save();

            return $invoice;
        });
    }

    /**
     * Turn a draft into a real invoice the client can pay.
     */
    public function publish(Invoice $invoice): Invoice
    {
        if ($invoice->status !== InvoiceStatus::Draft) {
            return $invoice;
        }

        $invoice->recalculate();
        $invoice->status = InvoiceStatus::Unpaid;
        $invoice->number ??= $this->numberFor($invoice);
        $invoice->issued_at = today();
        $invoice->save();

        Activity::log('invoice.published', "Published invoice {$invoice->number}", $invoice);

        return $invoice;
    }

    public function cancel(Invoice $invoice): Invoice
    {
        if (in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Draft], true)) {
            DB::transaction(function () use ($invoice): void {
                $invoice->update(['status' => InvoiceStatus::Cancelled]);
                // The periods on it may be invoiced again.
                $invoice->items()->whereNotNull('billing_key')->update(['billing_key' => null]);
            });
            Activity::log('invoice.cancelled', "Cancelled invoice {$invoice->displayNumber()}", $invoice);
            app(PlanChanges::class)->cancelForInvoice($invoice);
        }

        return $invoice;
    }

    public function numberFor(Invoice $invoice): string
    {
        $number = setting('billing.invoice_prefix').str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT);
        $candidate = $number;
        $suffix = 2;

        // Imported invoices keep their old numbers, which may look like ours.
        while (Invoice::query()->where('number', $candidate)->whereKeyNot($invoice->id)->exists()) {
            $candidate = $number.'-'.$suffix++;
        }

        return $candidate;
    }
}
