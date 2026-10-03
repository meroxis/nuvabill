<?php

namespace Nuvabill\Extensions\PayPal;

use App\Contracts\SavesPaymentMethods;
use App\Extensions\Gateways\ChargeResult;
use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\SavedMethod;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal Checkout (Orders API v2). The client approves the payment on PayPal and is sent back,
 * then the order is captured. A webhook confirms captures that finish later. A client can keep
 * their PayPal account for automatic renewals (PayPal calls it vaulting; the PayPal business account
 * must allow it).
 */
class PayPalGateway extends Gateway implements SavesPaymentMethods
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
        return $this->order($invoice, $returnUrl, $cancelUrl, false);
    }

    public function startSavingPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        return $this->order($invoice, $returnUrl, $cancelUrl, true);
    }

    private function order(Invoice $invoice, string $returnUrl, string $cancelUrl, bool $save): PaymentStart
    {
        $vault = $save ? ['attributes' => array_filter([
            'vault' => ['store_in_vault' => 'ON_SUCCESS', 'usage_type' => 'MERCHANT', 'customer_type' => 'CONSUMER'],
            'customer' => ($customer = $this->knownCustomer($invoice->client)) ? ['id' => $customer] : null,
        ])] : [];

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
                ] + $vault,
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

        $result = $this->resultFromCapture($capture, $invoice->id);
        $saved = $this->savedFromSource((array) $response->json('payment_source.paypal', []));

        return $saved === null ? $result : new PaymentResult($result->invoiceId, $result->amount, $result->currency, $result->reference, $result->fee, ['saved_method' => $saved->toArray()]);
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

        // PayPal signs every webhook. Without its signature headers there is nothing to check, so
        // a forged message costs no call to PayPal.
        foreach (['PAYPAL-AUTH-ALGO', 'PAYPAL-CERT-URL', 'PAYPAL-TRANSMISSION-ID', 'PAYPAL-TRANSMISSION-SIG', 'PAYPAL-TRANSMISSION-TIME'] as $header) {
            if (blank($request->header($header))) {
                return false;
            }
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

    public function startSavingMethod(Client $client, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $response = $this->api()->post($this->baseUrl().'/v3/vault/setup-tokens', array_filter([
            'customer' => ($customer = $this->knownCustomer($client)) ? ['id' => $customer] : null,
            'payment_source' => ['paypal' => [
                'usage_type' => 'MERCHANT',
                'experience_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                    'shipping_preference' => 'NO_SHIPPING',
                ],
            ]],
        ]));

        $approveUrl = collect($response->json('links', []))->first(fn (array $link): bool => ($link['rel'] ?? null) === 'approve')['href'] ?? null;

        if ($response->failed() || $approveUrl === null || ! is_string($response->json('id'))) {
            throw new RuntimeException('PayPal could not start saving the account: '.($response->json('details.0.description') ?? $response->json('message') ?? $response->status()));
        }

        // Only the client who started saving can finish it with this token.
        Cache::put('nuvabill.paypal-setup.'.$client->id, $response->json('id'), now()->addHour());

        return PaymentStart::redirect($approveUrl);
    }

    public function finishSavingMethod(Request $request, Client $client): ?SavedMethod
    {
        $token = (string) $request->query('approval_token_id', '');
        $expected = Cache::pull('nuvabill.paypal-setup.'.$client->id);

        if ($token === '' || ! is_string($expected) || ! hash_equals($expected, $token)) {
            return null;
        }

        $response = $this->api()->post($this->baseUrl().'/v3/vault/payment-tokens', [
            'payment_source' => ['token' => ['id' => $token, 'type' => 'SETUP_TOKEN']],
        ]);

        if ($response->failed() || ! is_string($response->json('id'))) {
            return null;
        }

        return SavedMethod::paypal($response->json('id'), $response->json('customer.id'), $response->json('payment_source.paypal.email_address'));
    }

    public function chargeSaved(PaymentMethod $method, Invoice $invoice, string $attemptKey): ChargeResult
    {
        if (! $this->supportsCurrency($invoice->currency)) {
            return ChargeResult::failed(__('PayPal cannot be charged in :currency.', ['currency' => $invoice->currency]));
        }

        $response = $this->api()
            // The same attempt sent twice creates one payment.
            ->withHeaders(['PayPal-Request-Id' => 'nuvabill-'.$attemptKey])
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $invoice->id,
                    'custom_id' => (string) $invoice->id,
                    'description' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
                    'amount' => ['currency_code' => $invoice->currency, 'value' => Money::toDecimal($invoice->balance())],
                ]],
                'payment_source' => ['paypal' => ['vault_id' => $method->reference]],
            ]);

        $capture = $response->json('purchase_units.0.payments.captures.0');

        if ($response->successful() && is_array($capture) && ($capture['status'] ?? null) === 'COMPLETED') {
            $result = $this->resultFromCapture($capture, $invoice->id);

            return ChargeResult::paid(new PaymentResult($result->invoiceId, $result->amount, $result->currency, $result->reference, $result->fee, ['autopay' => true]));
        }

        if (in_array($response->json('details.0.issue'), ['PAYER_ACTION_REQUIRED', 'PAYEE_ACCOUNT_RESTRICTED'], true) || $response->json('status') === 'PAYER_ACTION_REQUIRED') {
            return ChargeResult::needsClient(__('PayPal wants you to confirm this payment yourself.'));
        }

        return ChargeResult::failed((string) ($response->json('details.0.description') ?: __('PayPal could not take the payment.')));
    }

    public function forgetSaved(PaymentMethod $method): void
    {
        $this->api()->delete($this->baseUrl().'/v3/vault/payment-tokens/'.rawurlencode($method->reference));
    }

    /**
     * The PayPal customer the client's accounts were kept under before, if any.
     */
    private function knownCustomer(Client $client): ?string
    {
        $customer = PaymentMethod::query()->where('client_id', $client->id)->where('gateway', $this->slug())->whereNotNull('customer_reference')->latest('id')->value('customer_reference');

        return is_string($customer) && $customer !== '' ? $customer : null;
    }

    /**
     * @param  array<string, mixed>  $paypal
     */
    private function savedFromSource(array $paypal): ?SavedMethod
    {
        $vault = (array) ($paypal['attributes']['vault'] ?? []);

        if (! is_string($vault['id'] ?? null) || ! in_array($vault['status'] ?? null, ['VAULTED', 'APPROVED'], true)) {
            return null;
        }

        return SavedMethod::paypal($vault['id'], $vault['customer']['id'] ?? null, $paypal['email_address'] ?? null);
    }

    private function baseUrl(): string
    {
        return $this->setting('mode') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout(30)->acceptJson();
    }

    /**
     * PayPal's access token, kept (encrypted) until shortly before it expires, so each call does not
     * ask PayPal for a new one.
     */
    private function accessToken(): string
    {
        $key = 'nuvabill.paypal.token.'.hash('sha256', $this->baseUrl().'|'.$this->setting('client_id').'|'.$this->setting('client_secret'));
        $token = rescue(fn () => Crypt::decryptString((string) Cache::get($key)), report: false);

        if (is_string($token) && $token !== '') {
            return $token;
        }

        $response = Http::asForm()
            ->withBasicAuth((string) $this->setting('client_id'), (string) $this->setting('client_secret'))
            ->timeout(30)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);
        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('PayPal rejected the Client ID or Secret.');
        }

        Cache::put($key, Crypt::encryptString($token), max(60, (int) $response->json('expires_in') - 300));

        return $token;
    }
}
