<?php

namespace Nuvabill\Extensions\PayPal;

use App\Contracts\ChecksSavedCharges;
use App\Contracts\RepeatsUnclearCharges;
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
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;

/**
 * PayPal Checkout (Orders API v2). The client approves the payment on PayPal and is sent back,
 * then the order is captured. A webhook confirms captures that finish later. A client can keep
 * their PayPal account for automatic renewals (PayPal calls it vaulting; the PayPal business account
 * must allow it). An automatic payment PayPal is still processing is checked by the next nightly run.
 */
class PayPalGateway extends Gateway implements ChecksSavedCharges, RepeatsUnclearCharges, SavesPaymentMethods
{
    /**
     * Currencies PayPal accepts with two decimal places.
     */
    private const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'ILS', 'MXN', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD', 'SEK', 'CHF', 'THB', 'USD',
    ];

    /**
     * Marks an unclear automatic payment kept by its PayPal order, when PayPal did not send the capture.
     */
    private const ORDER_REFERENCE = 'order:';

    /**
     * Orders made before Nuvabill 0.6.12 name only the invoice in custom_id, without this site's
     * marker. On a site that used PayPal before, the update saves when it started marking orders
     * (this setting), and payments without the marker still count for this many days after it.
     * That leaves time for slow ones such as eChecks, however late the site updates.
     */
    private const MARKED_SINCE_SETTING = 'paypal.marked_orders_since';

    private const UNMARKED_ORDER_DAYS = 30;

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
                'help' => 'Recommended. Add a webhook for the URL shown on this page with the event PAYMENT.CAPTURE.COMPLETED and paste its ID. Without it, a checkout payment PayPal finishes later (for example an eCheck) must be marked paid by hand.',
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    /**
     * PayPal charges the invoice in its own currency and never converts it, so an exchange rate
     * does not make another currency payable.
     */
    public function convertsCurrency(): bool
    {
        return false;
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
                'custom_id' => $this->customId($invoice),
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

        // The order is read before it is captured. Only this site's order for this invoice pays it,
        // so an order made on another site that uses the same PayPal account, or one this site no
        // longer counts, is never captured here: its money is not taken without paying an invoice.
        $response = $this->api()->get($this->baseUrl().'/v2/checkout/orders/'.$orderId);

        if ($response->failed() || $this->invoiceFromCustomId($response->json('purchase_units.0.custom_id'), $this->takesUnmarkedOrders()) !== $invoice->id) {
            return null;
        }

        if ($response->json('status') === 'APPROVED') {
            $response = $this->api()->withBody('{}')->post($this->baseUrl().'/v2/checkout/orders/'.$orderId.'/capture');

            if ($response->status() === 422 && $response->json('details.0.issue') === 'ORDER_ALREADY_CAPTURED') {
                $response = $this->api()->get($this->baseUrl().'/v2/checkout/orders/'.$orderId);
            }

            if ($response->failed()) {
                return null;
            }
        }

        $capture = $response->json('purchase_units.0.payments.captures.0');

        if (! is_array($capture) || ($capture['status'] ?? null) !== 'COMPLETED' || ! is_string($capture['id'] ?? null)) {
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

        // PayPal sends the payments of the whole app to every webhook on it, for example those of
        // another Nuvabill site, so only payments with this site's marker count.
        $capture = is_array($event['resource'] ?? null) ? $event['resource'] : [];
        $invoiceId = $this->invoiceFromCustomId($capture['custom_id'] ?? null, $this->takesUnmarkedOrders());

        if ($invoiceId === null || ($capture['status'] ?? null) !== 'COMPLETED') {
            return WebhookResult::ignored('Capture is not for an invoice of this site.');
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

        $api = $this->api();
        $attempt = $this->attemptId($attemptKey);
        $send = fn (): Response => $api
            // The same attempt sent twice creates one payment. PayPal keeps this key for a few hours
            // only, so the attempt is also the invoice ID: a PayPal account that blocks repeated
            // invoice IDs refuses a later copy of the same attempt too.
            ->withHeaders(['PayPal-Request-Id' => $attempt, 'Prefer' => 'return=representation'])
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'reference_id' => (string) $invoice->id,
                    'custom_id' => $this->customId($invoice),
                    'invoice_id' => $attempt,
                    'description' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
                    'amount' => ['currency_code' => $invoice->currency, 'value' => Money::toDecimal($invoice->balance())],
                ]],
                'payment_source' => ['paypal' => ['vault_id' => $method->reference]],
            ]);

        $response = $send();

        // No answer to trust: PayPal may have taken the payment. Asking again with the same key
        // returns the first payment instead of making a second one.
        if ($this->gaveNoAnswer($response)) {
            Sleep::for(1)->second();
            $response = $send();
        }

        if ($this->gaveNoAnswer($response)) {
            return ChargeResult::pending(__('PayPal did not answer. The payment is checked before the next try.'));
        }

        $capture = $response->json('purchase_units.0.payments.captures.0');

        if ($response->successful() && is_array($capture)) {
            return $this->savedChargeResult($capture, $invoice);
        }

        // A finished order without the capture in the answer: the money may have been taken, so the
        // order is looked up before the next try.
        if ($response->successful() && $response->json('status') === 'COMPLETED') {
            $order = $response->json('id');

            return ChargeResult::pending(
                __('PayPal did not say whether it took the payment. The payment is checked before the next try.'),
                is_string($order) && preg_match('/^[A-Z0-9]+$/', $order) ? self::ORDER_REFERENCE.$order : null,
            );
        }

        // Only this try uses this invoice ID, so PayPal already has a payment for it. The same try is
        // refused again every time, so staff settle the invoice by hand.
        if ($response->json('details.0.issue') === 'DUPLICATE_INVOICE_ID') {
            return ChargeResult::pending(__('PayPal says this payment was sent before. Check it in PayPal and record it here, or ask the client to pay the invoice.'));
        }

        if (in_array($response->json('details.0.issue'), ['PAYER_ACTION_REQUIRED', 'PAYEE_ACCOUNT_RESTRICTED'], true) || $response->json('status') === 'PAYER_ACTION_REQUIRED') {
            return ChargeResult::needsClient(__('PayPal wants you to confirm this payment yourself.'));
        }

        return ChargeResult::failed((string) ($response->json('details.0.description') ?: __('PayPal could not take the payment.')));
    }

    public function checkSavedCharge(Invoice $invoice, string $attemptKey, ?string $reference, ?string $customer): ?ChargeResult
    {
        if ($reference !== null && str_starts_with($reference, self::ORDER_REFERENCE)) {
            return $this->checkSavedOrder($invoice, substr($reference, strlen(self::ORDER_REFERENCE)));
        }

        // Without PayPal's ID the payment cannot be looked up. Nuvabill sends the same try again
        // (RepeatsUnclearCharges): PayPal gives back the first payment for a few hours, and after
        // that a PayPal account that blocks repeated invoice IDs refuses a second payment.
        if ($reference === null || ! preg_match('/^[A-Z0-9]+$/', $reference)) {
            return null;
        }

        $response = $this->api()->get($this->baseUrl().'/v2/payments/captures/'.$reference);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException('PayPal could not be asked about the payment: '.($response->json('details.0.description') ?? $response->json('message') ?? $response->status()));
        }

        $capture = $response->json();

        // This site kept the capture's ID itself, so a try sent before the site marker still counts.
        if (! is_array($capture) || $this->invoiceFromCustomId($capture['custom_id'] ?? null, true) !== $invoice->id) {
            return null;
        }

        return $this->savedChargeResult($capture, $invoice);
    }

    /**
     * What became of an automatic payment PayPal finished without saying which capture took it.
     */
    private function checkSavedOrder(Invoice $invoice, string $orderId): ?ChargeResult
    {
        if (! preg_match('/^[A-Z0-9]+$/', $orderId)) {
            return null;
        }

        $response = $this->api()->get($this->baseUrl().'/v2/checkout/orders/'.$orderId);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw new RuntimeException('PayPal could not be asked about the payment: '.($response->json('details.0.description') ?? $response->json('message') ?? $response->status()));
        }

        $unit = (array) $response->json('purchase_units.0', []);
        $capture = $unit['payments']['captures'][0] ?? null;

        if ($this->invoiceFromCustomId($unit['custom_id'] ?? $capture['custom_id'] ?? null, true) !== $invoice->id) {
            return null;
        }

        if (is_array($capture)) {
            return $this->savedChargeResult($capture, $invoice);
        }

        return $response->json('status') === 'COMPLETED'
            ? ChargeResult::pending(__('PayPal did not say whether it took the payment. The payment is checked before the next try.'), self::ORDER_REFERENCE.$orderId)
            : ChargeResult::failed(__('PayPal could not take the payment.'));
    }

    /**
     * The custom_id of this site's orders: the invoice and this site's marker. Made from the app key,
     * so every site has its own and staff never need to enter one. PayPal allows 127 characters.
     */
    private function customId(Invoice $invoice): string
    {
        return $invoice->id.':'.$this->siteMarker();
    }

    /**
     * The invoice a payment's custom_id names, or null when this site did not make the order.
     * With $unmarked, a bare invoice number also counts, as orders made before 0.6.12 carry.
     */
    private function invoiceFromCustomId(mixed $customId, bool $unmarked): ?int
    {
        $customId = is_int($customId) ? (string) $customId : $customId;

        if (! is_string($customId)) {
            return null;
        }

        if (preg_match('/^([1-9]\d{0,17}):([0-9a-f]{24})$/', $customId, $match) === 1) {
            return hash_equals($this->siteMarker(), $match[2]) ? (int) $match[1] : null;
        }

        return $unmarked && preg_match('/^[1-9]\d{0,17}$/', $customId) === 1 ? (int) $customId : null;
    }

    /**
     * Whether a payment without the site marker may still be from an order this site made before
     * 0.6.12: only on a site that used PayPal then, and only for a while after it updated.
     */
    private function takesUnmarkedOrders(): bool
    {
        $since = setting(self::MARKED_SINCE_SETTING);
        $since = is_string($since) && $since !== '' ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($since), null, false) : null;

        return $since !== null && now()->lt($since->addDays(self::UNMARKED_ORDER_DAYS));
    }

    /**
     * Marks this site's orders at PayPal, so two Nuvabill sites on one PayPal app never pay each
     * other's invoices.
     */
    private function siteMarker(): string
    {
        return substr(hash_hmac('sha256', 'nuvabill-paypal-site', (string) config('app.key')), 0, 24);
    }

    /**
     * The ID PayPal gets for one try of an automatic payment, the same every time that try is sent.
     * It is sent as PayPal-Request-Id (at most 108 characters) and as the invoice ID (127). The site
     * part keeps two Nuvabill sites on one PayPal account apart, as both number invoices from 1.
     */
    private function attemptId(string $attemptKey): string
    {
        $site = substr(hash_hmac('sha256', 'nuvabill-paypal-attempt', (string) config('app.key')), 0, 12);
        $id = 'nuvabill-'.$site.'-'.$attemptKey;

        return strlen($id) <= 108 ? $id : 'nuvabill-'.$site.'-'.hash('sha256', $attemptKey);
    }

    /**
     * What a capture of an automatic payment means for the invoice.
     *
     * @param  array<string, mixed>  $capture
     */
    private function savedChargeResult(array $capture, Invoice $invoice): ChargeResult
    {
        $id = is_string($capture['id'] ?? null) && preg_match('/^[A-Z0-9]+$/', $capture['id']) ? $capture['id'] : null;

        return match ($capture['status'] ?? null) {
            'COMPLETED' => $id === null ? ChargeResult::failed(__('PayPal could not take the payment.')) : ChargeResult::paid(new PaymentResult(
                invoiceId: $invoice->id,
                amount: Money::toMinor($capture['amount']['value'] ?? 0),
                currency: (string) ($capture['amount']['currency_code'] ?? ''),
                reference: $id,
                fee: Money::toMinor($capture['seller_receivable_breakdown']['paypal_fee']['value'] ?? 0),
                meta: ['autopay' => true],
            )),
            // For example an eCheck, or a payment PayPal reviews first: PayPal takes the money later.
            'PENDING' => ChargeResult::pending(__('PayPal is still processing the payment.'), $id),
            default => ChargeResult::failed(__('PayPal could not take the payment.')),
        };
    }

    /**
     * A PayPal error, a timeout or too many requests: the payment may or may not have been made.
     */
    private function gaveNoAnswer(Response $response): bool
    {
        return $response->serverError() || in_array($response->status(), [408, 429], true);
    }

    public function forgetSaved(PaymentMethod $method): void
    {
        $response = $this->api()->delete($this->baseUrl().'/v3/vault/payment-tokens/'.rawurlencode($method->reference));

        // An account PayPal no longer knows is already gone.
        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException('PayPal could not remove the saved account: '.($response->json('details.0.description') ?? $response->json('message') ?? $response->status()));
        }
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
