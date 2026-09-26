<?php

namespace Nuvabill\Extensions\Stripe;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\Transaction;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Checkout. The client pays on a page hosted by Stripe, so card numbers never reach this server.
 */
class StripeGateway extends Gateway
{
    private const API = 'https://api.stripe.com/v1';

    /**
     * Stripe treats these currencies as having no or three decimal places. Nuvabill stores
     * two decimal places for every currency in v0.1, so these are not offered.
     */
    private const UNSUPPORTED_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
        'BHD', 'JOD', 'KWD', 'OMR', 'TND',
    ];

    /**
     * Webhooks older than this many seconds are rejected to stop replayed requests.
     */
    private const SIGNATURE_TOLERANCE = 300;

    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "Stripe". Many hosts use "Credit or debit card".',
            ],
            'secret_key' => [
                'label' => 'Secret key',
                'type' => 'password',
                'required' => true,
                'help' => 'From Stripe Dashboard → Developers → API keys. Starts with sk_live_ or sk_test_.',
            ],
            'webhook_secret' => [
                'label' => 'Webhook signing secret',
                'type' => 'password',
                'required' => true,
                'help' => 'Add a webhook for the URL shown on this page with the event checkout.session.completed, then paste its signing secret (whsec_...).',
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return ! in_array(strtoupper($currency), self::UNSUPPORTED_CURRENCIES, true);
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        $response = $this->api()->asForm()->post(self::API.'/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => $returnUrl.$separator.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $invoice->id,
            'customer_email' => $invoice->client->email,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'unit_amount' => $invoice->balance(),
                    'product_data' => ['name' => __('Invoice :number', ['number' => $invoice->displayNumber()])],
                ],
            ]],
            'metadata' => ['invoice_id' => (string) $invoice->id],
            'payment_intent_data' => ['metadata' => ['invoice_id' => (string) $invoice->id]],
        ]);

        if ($response->failed() || ! is_string($response->json('url'))) {
            throw new RuntimeException('Stripe could not start the payment: '.($response->json('error.message') ?? $response->status()));
        }

        return PaymentStart::redirect($response->json('url'));
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        $sessionId = (string) $request->query('session_id', '');

        if (! str_starts_with($sessionId, 'cs_')) {
            return null;
        }

        $response = $this->api()->get(self::API.'/checkout/sessions/'.$sessionId);

        if ($response->failed()) {
            return null;
        }

        $result = $this->resultFromSession($response->json());

        return $result?->invoiceId === $invoice->id ? $result : null;
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $payload = $request->getContent();

        if (! $this->hasValidSignature($payload, (string) $request->header('Stripe-Signature', ''))) {
            return WebhookResult::invalid('Invalid Stripe signature.');
        }

        $event = json_decode($payload, true);

        if (! in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return WebhookResult::ignored();
        }

        $result = $this->resultFromSession($event['data']['object'] ?? []);

        return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Session not paid yet.');
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Transaction $payment, int $amount): RefundResult
    {
        if (! str_starts_with((string) $payment->reference, 'pi_')) {
            throw new RuntimeException('This payment has no Stripe payment ID, so Stripe cannot refund it.');
        }

        $response = $this->api()->asForm()->post(self::API.'/refunds', [
            'payment_intent' => $payment->reference,
            'amount' => $amount,
            'metadata' => ['invoice_id' => (string) $payment->invoice_id],
        ]);

        if ($response->failed() || ! in_array($response->json('status'), ['succeeded', 'pending'], true)) {
            throw new RuntimeException('Stripe could not refund the payment: '.($response->json('error.message') ?? $response->json('status') ?? $response->status()));
        }

        return new RefundResult(
            amount: (int) $response->json('amount', $amount),
            reference: (string) $response->json('id'),
            meta: ['status' => $response->json('status')],
        );
    }

    public function hasValidSignature(string $payload, string $header): bool
    {
        $secret = (string) $this->setting('webhook_secret', '');

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || abs(time() - $timestamp) > self::SIGNATURE_TOLERANCE) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $session
     */
    private function resultFromSession(array $session): ?PaymentResult
    {
        $invoiceId = (int) ($session['metadata']['invoice_id'] ?? $session['client_reference_id'] ?? 0);

        if ($invoiceId === 0 || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        $paymentIntent = $session['payment_intent'] ?? null;
        $reference = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;

        return new PaymentResult(
            invoiceId: $invoiceId,
            amount: (int) ($session['amount_total'] ?? 0),
            currency: strtoupper((string) ($session['currency'] ?? '')),
            reference: (string) ($reference ?: $session['id']),
            meta: ['checkout_session' => $session['id'] ?? null],
        );
    }

    private function api(): PendingRequest
    {
        return Http::withToken((string) $this->setting('secret_key'))->timeout(30)->acceptJson();
    }
}
