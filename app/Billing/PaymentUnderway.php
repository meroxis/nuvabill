<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\PaymentIntent;

/**
 * Whether money for an invoice may still arrive: a saved card charge that is not finished or will
 * be tried again, or a gateway payment started in the last day. An invoice like that is not
 * cancelled, so the payment is not recorded on a cancelled invoice and lost.
 */
class PaymentUnderway
{
    public static function on(Invoice $invoice): bool
    {
        return $invoice->autopay_pending !== null
            || ($invoice->autopay_retry_at !== null && $invoice->autopay_retry_at->isFuture())
            || PaymentIntent::query()
                ->where('invoice_id', $invoice->id)
                ->where('status', PaymentIntent::STATUS_PENDING)
                ->where('created_at', '>=', now()->subDay())
                ->exists();
    }
}
