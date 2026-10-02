<?php

namespace App\Contracts;

use App\Extensions\Gateways\ChargeResult;
use App\Models\Invoice;

/**
 * A gateway that can say what became of an automatic payment whose result was not known (still
 * processing at the bank, or no answer). Nuvabill asks before it charges the invoice again.
 * Gateways without it are charged again with the same attempt key, which the gateway charges once.
 */
interface ChecksSavedCharges
{
    /**
     * The payment's result now, or null when the gateway has no payment for this attempt: it never
     * arrived, so charging again is safe.
     *
     * @param  string  $attemptKey  The key the payment was sent with.
     * @param  string|null  $reference  The gateway's ID for the payment, when it sent one.
     * @param  string|null  $customer  The gateway customer the saved method belongs to.
     */
    public function checkSavedCharge(Invoice $invoice, string $attemptKey, ?string $reference, ?string $customer): ?ChargeResult;
}
