<?php

namespace App\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Activity;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Creates invoices and keeps their totals and numbers correct.
 */
class InvoiceManager
{
    /**
     * @param  list<array{type?: string, description: string, amount: int, service_id?: int|null, period_start?: CarbonInterface|string|null, period_end?: CarbonInterface|string|null}>  $items
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
            $invoice = Invoice::create([
                'client_id' => $client->id,
                'status' => $status,
                'currency' => $currency ?? $client->currency,
                'issued_at' => today(),
                'due_at' => $dueAt ?? today(),
                'notes' => $notes,
            ]);

            foreach ($items as $item) {
                $invoice->items()->create([
                    'type' => $item['type'] ?? 'manual',
                    'description' => $item['description'],
                    'amount' => $item['amount'],
                    'service_id' => $item['service_id'] ?? null,
                    'period_start' => $item['period_start'] ?? null,
                    'period_end' => $item['period_end'] ?? null,
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
            $invoice->update(['status' => InvoiceStatus::Cancelled]);
            Activity::log('invoice.cancelled', "Cancelled invoice {$invoice->displayNumber()}", $invoice);
        }

        return $invoice;
    }

    public function numberFor(Invoice $invoice): string
    {
        return setting('billing.invoice_prefix').str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT);
    }
}
