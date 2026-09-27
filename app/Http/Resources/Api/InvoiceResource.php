<?php

namespace App\Http\Resources\Api;

use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts are in cents (minor units) of the invoice currency.
 *
 * @mixin Invoice
 */
class InvoiceResource extends JsonResource
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
            'status' => $this->status->value,
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'tax' => $this->tax,
            'tax_name' => $this->tax_name,
            'tax_rate_percent' => $this->tax_rate !== null ? $this->tax_rate / 100 : null,
            'tax_included' => (bool) $this->tax_inclusive,
            'total' => $this->total,
            'amount_paid' => $this->amount_paid,
            'balance' => $this->balance(),
            'total_label' => money($this->total, $this->currency),
            'issued_at' => $this->issued_at?->toDateString(),
            'due_at' => $this->due_at?->toDateString(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item): array => [
                'type' => $item->type,
                'description' => $item->description,
                'amount' => $item->amount,
                'taxed' => (bool) $item->taxed,
                'service_id' => $item->service_id,
                'domain_id' => $item->domain_id,
            ])),
            'payments' => $this->whenLoaded('transactions', fn () => $this->transactions->map(fn ($transaction): array => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'gateway' => $transaction->gateway,
                'reference' => $transaction->reference,
                'amount' => $transaction->amount,
                'paid_at' => $transaction->paid_at?->toIso8601String(),
            ])),
        ];
    }
}
