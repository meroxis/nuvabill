<?php

namespace App\Contracts;

use App\Extensions\Gateways\ChargeResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\SavedMethod;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Illuminate\Http\Request;

/**
 * A gateway that can keep a card or PayPal account for later charges, so renewals pay
 * themselves. Card numbers stay with the gateway; Nuvabill keeps only its reference.
 */
interface SavesPaymentMethods
{
    /**
     * Pay the invoice now and keep the method. The payment result the gateway reports on return
     * or by webhook carries the saved method in its meta under "saved_method" (see SavedMethod::toArray()).
     */
    public function startSavingPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart;

    /**
     * Keep a method without paying anything now (Payment methods → Add a card).
     */
    public function startSavingMethod(Client $client, string $returnUrl, string $cancelUrl): PaymentStart;

    /**
     * The client came back from saving a method. Null when nothing was saved.
     */
    public function finishSavingMethod(Request $request, Client $client): ?SavedMethod;

    /**
     * Charge the invoice's balance to a saved method, without the client.
     */
    public function chargeSaved(PaymentMethod $method, Invoice $invoice, string $attemptKey): ChargeResult;

    /**
     * Delete the method at the gateway too.
     */
    public function forgetSaved(PaymentMethod $method): void;
}
