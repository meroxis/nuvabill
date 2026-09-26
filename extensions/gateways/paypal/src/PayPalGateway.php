<?php

namespace Nuvabill\Extensions\PayPal;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal Checkout (Orders API v2). The client approves the payment on PayPal and is sent back,
 * then the order is captured. A webhook confirms captures that finish later.
 */
class PayPalGateway extends Gateway
{
    /**
     * Currencies PayPal accepts with two decimal places.
     */
    private const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'ILS', 'MXN', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "PayPal".',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'required' => true,
                'options' => ['live' => 'Live payments', 'sandbox' => 'Sandbox (testing)'],
            ],
            'client_id' => [
                'label' => 'Client ID',
                'type' => 'text',
                'required' => true,
                'help' => 'From developer.paypal.com → Apps & Credentials.',
            ],
            'client_secret' => [
                'label' => 'Secret',
                'type' => 'password',
                'required' => true,
            ],
            'webhook_id' => [
                'label' => 'Webhook ID',
                'type' => 'text',
                'help' => 'Optional. Add a webhook for the URL shown on this page with the event PAYMENT.CAPTURE.COMPLETED and paste its ID.',
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $response = $this->api()->post($this->baseUrl().'/v2/checkout/orders', [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => (string) $invoice->id,
                'custom_id' => (string) $invoice->id,
                'description' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
                'amount' => [
                    'currency_code' => $invoice->currency,
                    'value' => Money::toDecimal($invoice->balance()),
                ],
            ]],
            'payment_source' => [
                'paypal' => [
                    'experience_context' => [
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl,
                        'user_action' => 'PAY_NOW',
                        'shipping_preference' => 'NO_SHIPPING',
                    ],
                ],
            ],
        ]);

        $approveUrl = collect($response->json('links', []))
            ->first(fn (array $link): bool => in_array($link['rel'] ?? null, ['payer-action', 'approve'], true))['href'] ?? null;

        if ($response->failed() || $approveUrl === null) {
            throw new RuntimeException('PayPal could not start the payment: '.($response->json('message') ?? $response->status()));
        }

        return PaymentStart::redirect($approveUrl);
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        $orderId = (string) $request->query('token', '');

        if ($orderId === '' || ! preg_match('/^[A-Z0-9]+$/', $orderId)) {
            return null;
        }

        $response = $this->api()->withBody('{}')->post($this->baseUrl().'/v2/checkout/orders/'.$orderId.'/capture');

        if ($response->status() === 422 && $response->json('details.0.issue') === 'ORDER_ALREADY_CAPTURED') {
            $response = $this->api()->get($this->baseUrl().'/v2/checkout/orders/'.$orderId);
        }

        if ($response->failed()) {
            return null;
        }

        $unit = $response->json('purchase_units.0', []);
        $capture = $unit['payments']['captures'][0] ?? null;

        if (($capture['status'] ?? null) !== 'COMPLETED' || (int) ($unit['custom_id'] ?? $capture['custom_id'] ?? 0) !== $invoice->id) {
            return null;
        }

        return $this->resultFromCapture($capture, $invoice->id);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $event = $request->json()->all();

        if (($event['event_type'] ?? null) !== 'PAYMENT.CAPTURE.COMPLETED') {
            return WebhookResult::ignored();
        }

        if (! $this->webhookIsVerified($request, $event)) {
            return WebhookResult::invalid('PayPal could not verify this webhook.');
        }

        $capture = $event['resource'] ?? [];
        $invoiceId = (int) ($capture['custom_id'] ?? 0);

        if ($invoiceId === 0 || ($capture['status'] ?? null) !== 'COMPLETED') {
            return WebhookResult::ignored('Capture is not for a Nuvabill invoice.');
        }

        return WebhookResult::paid($this->resultFromCapture($capture, $invoiceId));
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Transaction $payment, int $amount): RefundResult
    {
        $captureId = (string) $payment->reference;

        if (! preg_match('/^[A-Z0-9]+$/', $captureId)) {
            throw new RuntimeException('This payment has no PayPal capture ID, so PayPal cannot refund it.');
        }

        $response = $this->api()->post($this->baseUrl().'/v2/payments/captures/'.$captureId.'/refund', [
            'amount' => [
                'currency_code' => $payment->currency,
                'value' => Money::toDecimal($amount),
            ],
            'custom_id' => (string) $payment->invoice_id,
        ]);

        if ($response->failed() || ! in_array($response->json('status'), ['COMPLETED', 'PENDING'], true)) {
            throw new RuntimeException('PayPal could not refund the payment: '.($response->json('details.0.description') ?? $response->json('message') ?? $response->status()));
        }

        return new RefundResult(
            amount: $amount,
            reference: (string) $response->json('id'),
            meta: ['status' => $response->json('status')],
        );
    }

    /**
     * @param  array<string, mixed>  $event
     */
    private function webhookIsVerified(Request $request, array $event): bool
    {
        $webhookId = (string) $this->setting('webhook_id', '');

        if ($webhookId === '') {
            return false;
        }

        $response = $this->api()->post($this->baseUrl().'/v1/notifications/verify-webhook-signature', [
            'auth_algo' => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url' => $request->header('PAYPAL-CERT-URL'),
            'transmission_id' => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id' => $webhookId,
            'webhook_event' => $event,
        ]);

        return $response->successful() && $response->json('verification_status') === 'SUCCESS';
    }

    /**
     * @param  array<string, mixed>  $capture
     */
    private function resultFromCapture(array $capture, int $invoiceId): PaymentResult
    {
        return new PaymentResult(
            invoiceId: $invoiceId,
            amount: Money::toMinor($capture['amount']['value'] ?? 0),
            currency: (string) ($capture['amount']['currency_code'] ?? ''),
            reference: (string) $capture['id'],
            fee: Money::toMinor($capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0),
        );
    }

    private function baseUrl(): string
    {
        return $this->setting('mode') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function api(): PendingRequest
    {
        $token = Http::asForm()
            ->withBasicAuth((string) $this->setting('client_id'), (string) $this->setting('client_secret'))
            ->timeout(30)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials'])
            ->json('access_token');

        if (! is_string($token)) {
            throw new RuntimeException('PayPal rejected the Client ID or Secret.');
        }

        return Http::withToken($token)->timeout(30)->acceptJson();
    }
}
