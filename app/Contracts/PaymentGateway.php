<?php

namespace App\Contracts;

use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Request;

/**
 * A way for clients to pay invoices: Stripe, PayPal, bank transfer and so on.
 */
interface PaymentGateway
{
    public function slug(): string;

    /**
     * The name clients see on the payment page.
     */
    public function name(): string;

    /**
     * Fields shown on the gateway's settings page.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>}>
     */
    public function settingsFields(): array;

    public function isConfigured(): bool;

    public function supportsCurrency(string $currency): bool;

    /**
     * Begin paying the invoice's balance. Either redirect the client or show instructions.
     */
    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart;

    /**
     * Called when the client comes back from the gateway. Return a result if the payment is confirmed.
     */
    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult;

    /**
     * Handle a server-to-server notification from the gateway.
     */
    public function handleWebhook(Request $request): WebhookResult;

    /**
     * Whether this gateway can send money back to the client through its API.
     */
    public function supportsRefunds(): bool;

    /**
     * Send part or all of a payment back to the client.
     *
     * @param  int  $amount  Amount to refund, in minor units.
     *
     * @throws \RuntimeException When the gateway refuses the refund.
     */
    public function refund(Transaction $payment, int $amount): RefundResult;
}
