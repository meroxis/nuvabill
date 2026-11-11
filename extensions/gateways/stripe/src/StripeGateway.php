<?php

namespace Nuvabill\Extensions\Stripe;

use App\Contracts\ChecksSavedCharges;
use App\Contracts\SavesPaymentMethods;
use App\Contracts\SendsRefundsOnce;
use App\Extensions\Gateways\ChargeResult;
use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentReversal;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\RefundResult;
use App\Extensions\Gateways\RefundUnclear;
use App\Extensions\Gateways\SavedMethod;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Transaction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Stripe Checkout. The client pays on a page hosted by Stripe, so card numbers never reach this server.
 * A client can keep the card at Stripe for automatic renewals; Nuvabill then charges it by its
 * reference without the client. A charge the bank is still processing is settled by the
 * payment_intent.succeeded webhook, or checked by the next nightly run.
 */
class StripeGateway extends Gateway implements ChecksSavedCharges, SavesPaymentMethods, SendsRefundsOnce
{
    private const API = 'https://api.stripe.com/v1';

    /**
     * Nuvabill stores two decimal places for every currency and sends Stripe that amount, so Stripe
     * is offered only for the currencies listed here: Stripe's presentment currencies that Stripe
     * charges with exactly two decimal places (docs.stripe.com/currencies). A currency that is not
     * listed is refused until someone has checked it, so a new currency can never be charged with
     * the wrong amount. Left out on purpose:
     * - currencies with no decimal places or three decimal places at Stripe (JPY, KWD and others);
     * - ISK and UGX, which Stripe takes with two decimals that must be 00 (an amount with a
     *   fraction would be refused or rounded);
     * - IQD and LYD, which have three decimal places in ISO 4217 and which Stripe does not take;
     * - currencies Stripe does not take, or that have not been checked at Stripe (BTN, ERN, GHS,
     *   MRU, SDG, SSP, STN, SVC, TMT, VES, XCG and ZWG), so a client is not sent to a payment
     *   that can only fail.
     * HUF and TWD are fine: Stripe charges them with two decimals and only rounds payouts.
     */
    private const SUPPORTED_CURRENCIES = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BMD', 'BND', 'BOB',
        'BRL', 'BSD', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CNY', 'COP', 'CRC', 'CVE', 'CZK', 'DKK', 'DOP',
        'DZD', 'EGP', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GIP', 'GMD', 'GTQ', 'GYD', 'HKD', 'HNL',
        'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'JMD', 'KES', 'KGS', 'KHR', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD',
        'LSL', 'MAD', 'MDL', 'MKD', 'MMK', 'MNT', 'MOP', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN',
        'NIO', 'NOK', 'NPR', 'NZD', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RSD', 'RUB', 'SAR',
        'SBD', 'SCR', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SZL', 'THB', 'TJS', 'TOP', 'TRY', 'TTD', 'TWD',
        'TZS', 'UAH', 'USD', 'UYU', 'UZS', 'WST', 'XCD', 'YER', 'ZAR', 'ZMW',
    ];

    /**
     * Webhooks older than this many seconds are rejected to stop replayed requests.
     */
    private const SIGNATURE_TOLERANCE = 300;

    /**
     * Events that say money of a payment went back, or came back after a dispute.
     */
    private const REVERSAL_EVENTS = ['charge.refunded', 'charge.dispute.created', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'];

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
                'help' => 'Add a webhook for the URL shown on this page with the events checkout.session.completed, checkout.session.async_payment_succeeded and payment_intent.succeeded, then paste its signing secret (whsec_...). Add charge.refunded, charge.dispute.created, charge.dispute.funds_withdrawn and charge.dispute.funds_reinstated too, so refunds and disputes made in Stripe are recorded here.',
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return in_array(strtoupper($currency), self::SUPPORTED_CURRENCIES, true);
    }

    /**
     * Stripe charges the invoice in its own currency and never converts it, so an exchange rate
     * does not make another currency payable.
     */
    public function convertsCurrency(): bool
    {
        return false;
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        return $this->checkout($invoice, $returnUrl, $cancelUrl, false);
    }

    public function startSavingPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        return $this->checkout($invoice, $returnUrl, $cancelUrl, true);
    }

    private function checkout(Invoice $invoice, string $returnUrl, string $cancelUrl, bool $save): PaymentStart
    {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        $customer = $save ? ['customer' => $this->customerFor($invoice->client)] : ['customer_email' => $invoice->client->email];

        $response = $this->api()->asForm()->post(self::API.'/checkout/sessions', $customer + [
            'mode' => 'payment',
            'success_url' => $returnUrl.$separator.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => (string) $invoice->id,
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($invoice->currency),
                    'unit_amount' => $invoice->balance(),
                    'product_data' => ['name' => __('Invoice :number', ['number' => $invoice->displayNumber()])],
                ],
            ]],
            // The site marker tells this site's payments apart from other payments on the same Stripe account.
            'metadata' => ['invoice_id' => (string) $invoice->id, 'nuvabill_site' => $this->siteMarker()] + ($save ? ['save' => '1'] : []),
            'payment_intent_data' => ['metadata' => ['invoice_id' => (string) $invoice->id, 'nuvabill_site' => $this->siteMarker()]] + ($save ? ['setup_future_usage' => 'off_session'] : []),
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

        $response = $this->api()->get(self::API.'/checkout/sessions/'.$sessionId, ['expand' => ['payment_intent.payment_method']]);

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

        if (in_array($event['type'] ?? null, self::REVERSAL_EVENTS, true)) {
            $reversal = $this->reversalFromEvent((string) $event['type'], (array) ($event['data']['object'] ?? []));

            return $reversal ? WebhookResult::reversed($reversal) : WebhookResult::ignored('No money went back on a payment.');
        }

        if (($event['type'] ?? null) === 'payment_intent.succeeded') {
            $result = $this->resultFromSavedCharge((array) ($event['data']['object'] ?? []));

            return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Not an automatic payment of this site.');
        }

        if (! in_array($event['type'] ?? null, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return WebhookResult::ignored();
        }

        $result = $this->resultFromSession($event['data']['object'] ?? []);

        return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Session not paid yet, or not started by this site.');
    }

    public function webhookEvents(): array
    {
        return ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'payment_intent.succeeded', ...self::REVERSAL_EVENTS];
    }

    /**
     * A refund made in Stripe, or a dispute, as Stripe reports it. Stripe sends these for the whole
     * account; only a payment recorded here with the same payment ID is touched when it is saved.
     *
     * @param  array<string, mixed>  $object  The charge (refunds) or the dispute.
     */
    private function reversalFromEvent(string $type, array $object): ?PaymentReversal
    {
        $intent = $object['payment_intent'] ?? null;
        $intent = is_array($intent) ? ($intent['id'] ?? null) : $intent;
        $id = $object['id'] ?? null;
        $currency = strtoupper((string) ($object['currency'] ?? ''));

        if (! is_string($intent) || ! str_starts_with($intent, 'pi_') || ! is_string($id) || $id === '') {
            return null;
        }

        if ($type === 'charge.refunded') {
            // The charge says how much was refunded in all, so a refund sent from here is not counted twice.
            $refunded = (int) ($object['amount_refunded'] ?? 0);

            return $refunded > 0 ? PaymentReversal::refund($intent, $refunded, $currency, $id.'-refunded-'.$refunded, $refunded) : null;
        }

        $amount = (int) ($object['amount'] ?? 0);

        if ($amount <= 0) {
            return null;
        }

        if ($type === 'charge.dispute.funds_reinstated') {
            return PaymentReversal::chargebackUndone($intent, $amount, $currency, $id);
        }

        // A question from the bank (Stripe calls it an inquiry) takes no money yet.
        if ($type === 'charge.dispute.created' && str_starts_with((string) ($object['status'] ?? ''), 'warning_')) {
            return null;
        }

        return PaymentReversal::chargeback($intent, $amount, $currency, $id);
    }

    public function supportsRefunds(): bool
    {
        return true;
    }

    public function refund(Transaction $payment, int $amount): RefundResult
    {
        return $this->refundOnce($payment, $amount, 'once-'.Str::lower(Str::random(20)));
    }

    public function refundOnce(Transaction $payment, int $amount, string $attemptKey): RefundResult
    {
        if (! str_starts_with((string) $payment->reference, 'pi_')) {
            throw new RuntimeException('This payment has no Stripe payment ID, so Stripe cannot refund it.');
        }

        try {
            $response = $this->api()->asForm()
                // The same refund sent twice (a timeout, then a retry) sends the money back once.
                // Stripe keeps keys for about 24 hours; the attempt in the metadata lets staff find
                // the refund in Stripe after that.
                ->withHeaders(['Idempotency-Key' => $this->idempotencyKey('refund-'.$attemptKey)])
                ->post(self::API.'/refunds', [
                    'payment_intent' => $payment->reference,
                    'amount' => $amount,
                    'metadata' => ['invoice_id' => (string) $payment->invoice_id, 'attempt' => $attemptKey, 'nuvabill_site' => $this->siteMarker()],
                ]);
        } catch (ConnectionException $exception) {
            throw new RefundUnclear('Stripe did not answer the refund.', previous: $exception);
        }

        // A Stripe error, too many requests, or the same key still running: the refund may have been made.
        if ($response->serverError() || in_array($response->status(), [409, 429], true)) {
            throw new RefundUnclear('Stripe did not answer the refund (HTTP '.$response->status().').');
        }

        if ($response->failed() || ! in_array($response->json('status'), ['succeeded', 'pending'], true)) {
            throw new RuntimeException('Stripe could not refund the payment: '.($response->json('error.message') ?? $response->json('status') ?? $response->status()));
        }

        return new RefundResult(
            amount: (int) $response->json('amount', $amount),
            reference: (string) $response->json('id'),
            meta: ['status' => $response->json('status')],
        );
    }

    public function refundKeyHours(): int
    {
        return 23;
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
     * A paid Checkout session this site started. Stripe sends the sessions of the whole account,
     * for example Payment Links or another site's payments, so only sessions with this site's
     * marker count. client_reference_id is never trusted: a buyer can set it on a Payment Link.
     *
     * @param  array<string, mixed>  $session
     */
    private function resultFromSession(array $session): ?PaymentResult
    {
        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $invoiceId = (string) ($metadata['invoice_id'] ?? '');

        if (! ctype_digit($invoiceId) || (int) $invoiceId === 0 || ($session['payment_status'] ?? null) !== 'paid') {
            return null;
        }

        $invoiceId = (int) $invoiceId;

        if (! hash_equals($this->siteMarker(), (string) ($metadata['nuvabill_site'] ?? '')) && ! $this->returnsHere($session, $invoiceId)) {
            return null;
        }

        $paymentIntent = $session['payment_intent'] ?? null;
        $reference = is_array($paymentIntent) ? ($paymentIntent['id'] ?? null) : $paymentIntent;

        $saved = ($metadata['save'] ?? null) === '1' ? $this->savedFromIntent($paymentIntent, $session['customer'] ?? null) : null;

        return new PaymentResult(
            invoiceId: $invoiceId,
            amount: (int) ($session['amount_total'] ?? 0),
            currency: strtoupper((string) ($session['currency'] ?? '')),
            reference: (string) ($reference ?: $session['id']),
            meta: array_filter(['checkout_session' => $session['id'] ?? null, 'saved_method' => $saved?->toArray()]),
        );
    }

    /**
     * Sessions started before Nuvabill 0.6.12 have no site marker. They still count when they send
     * the client back to this invoice on this site, which no other payment on the account does.
     * Stripe ends unpaid sessions after a day, so this can go in a later release.
     *
     * @param  array<string, mixed>  $session
     */
    private function returnsHere(array $session, int $invoiceId): bool
    {
        $successUrl = (string) ($session['success_url'] ?? '');

        return $successUrl !== '' && str_starts_with($successUrl, route('client.invoices.return', [$invoiceId, $this->slug()]).'?');
    }

    /**
     * Marks this site's Checkout sessions and payments at Stripe. Made from the app key, so every
     * site has its own and staff never need to enter one.
     */
    private function siteMarker(): string
    {
        return substr(hash_hmac('sha256', 'nuvabill-stripe-checkout', (string) config('app.key')), 0, 32);
    }

    /**
     * The Idempotency-Key of one try of an automatic payment or a refund, at most 255 characters.
     * Unclear payment tries are found again by the attempt in their metadata, not by this key.
     */
    private function idempotencyKey(string $attemptKey): string
    {
        $site = substr($this->siteMarker(), 0, 12);
        $key = 'nuvabill-'.$site.'-'.$attemptKey;

        return strlen($key) <= 255 ? $key : 'nuvabill-'.$site.'-'.hash('sha256', $attemptKey);
    }

    public function startSavingMethod(Client $client, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $separator = str_contains($returnUrl, '?') ? '&' : '?';

        $response = $this->api()->asForm()->post(self::API.'/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $this->customerFor($client),
            'payment_method_types' => ['card'],
            'success_url' => $returnUrl.$separator.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'metadata' => ['client_id' => (string) $client->id],
        ]);

        if ($response->failed() || ! is_string($response->json('url'))) {
            throw new RuntimeException('Stripe could not start saving a card: '.($response->json('error.message') ?? $response->status()));
        }

        return PaymentStart::redirect($response->json('url'));
    }

    public function finishSavingMethod(Request $request, Client $client): ?SavedMethod
    {
        $sessionId = (string) $request->query('session_id', '');

        if (! str_starts_with($sessionId, 'cs_')) {
            return null;
        }

        $session = $this->api()->get(self::API.'/checkout/sessions/'.$sessionId, ['expand' => ['setup_intent.payment_method']]);
        $intent = $session->json('setup_intent');

        if ($session->failed() || (string) $session->json('metadata.client_id') !== (string) $client->id || ! is_array($intent) || ($intent['status'] ?? null) !== 'succeeded') {
            return null;
        }

        return $this->savedFromPaymentMethod($intent['payment_method'] ?? null, $session->json('customer'));
    }

    public function chargeSaved(PaymentMethod $method, Invoice $invoice, string $attemptKey): ChargeResult
    {
        if (! $this->supportsCurrency($invoice->currency)) {
            return ChargeResult::failed(__('This card cannot be charged in :currency.', ['currency' => $invoice->currency]));
        }

        $response = $this->api()->asForm()
            // The same attempt sent twice (a timeout, then a retry) charges the card once. Stripe keeps
            // keys for the whole account, so the site part keeps two Nuvabill sites on one account apart,
            // as both number invoices from 1.
            ->withHeaders(['Idempotency-Key' => $this->idempotencyKey($attemptKey)])
            ->post(self::API.'/payment_intents', [
                'amount' => $invoice->balance(),
                'currency' => strtolower($invoice->currency),
                'customer' => $method->customer_reference,
                'payment_method' => $method->reference,
                'off_session' => 'true',
                'confirm' => 'true',
                'description' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
                // The attempt key finds this payment again when Stripe's answer is lost.
                'metadata' => ['invoice_id' => (string) $invoice->id, 'autopay' => '1', 'attempt' => $attemptKey, 'nuvabill_site' => $this->siteMarker()],
            ]);

        // No answer to trust (a Stripe error, too many requests, or the same key still running):
        // the card may have been charged, so the payment is checked before the next try.
        if ($response->serverError() || in_array($response->status(), [409, 429], true)) {
            return ChargeResult::pending(__('The payment service did not answer. The payment is checked before the next try.'));
        }

        if ($response->successful()) {
            return $this->resultFromIntent($response->json(), $invoice);
        }

        if ($response->json('error.code') === 'authentication_required' || $response->json('error.payment_intent.status') === 'requires_action') {
            return ChargeResult::needsClient(__('Your bank wants you to confirm this payment yourself.'));
        }

        // Stripe's own message is written for card holders, for example "Your card has insufficient funds."
        return ChargeResult::failed((string) ($response->json('error.message') ?: __('The card could not be charged.')));
    }

    public function checkSavedCharge(Invoice $invoice, string $attemptKey, ?string $reference, ?string $customer): ?ChargeResult
    {
        if ($reference !== null && str_starts_with($reference, 'pi_')) {
            $response = $this->api()->get(self::API.'/payment_intents/'.rawurlencode($reference));
            $intent = $response->successful() ? $response->json() : null;
        } elseif ($customer !== null && $customer !== '') {
            // Without its ID, the payment is found by the attempt key it was sent with.
            $response = $this->api()->get(self::API.'/payment_intents', ['customer' => $customer, 'limit' => 100]);
            $intent = collect((array) $response->json('data', []))->first(fn (mixed $intent): bool => is_array($intent) && ($intent['metadata']['attempt'] ?? null) === $attemptKey);
        } else {
            return null;
        }

        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException('Stripe could not be asked about the payment: '.($response->json('error.message') ?? $response->status()));
        }

        if (! is_array($intent) || (string) ($intent['metadata']['invoice_id'] ?? '') !== (string) $invoice->id) {
            return null;
        }

        return $this->resultFromIntent($intent, $invoice);
    }

    /**
     * @param  array<string, mixed>  $intent  The PaymentIntent of a saved card charge.
     */
    private function resultFromIntent(array $intent, Invoice $invoice): ChargeResult
    {
        $id = is_string($intent['id'] ?? null) ? $intent['id'] : null;

        return match ($intent['status'] ?? null) {
            'succeeded' => $id === null ? ChargeResult::failed(__('The card could not be charged.')) : ChargeResult::paid(new PaymentResult(
                invoiceId: $invoice->id,
                amount: (int) ($intent['amount_received'] ?? $intent['amount'] ?? 0),
                currency: strtoupper((string) ($intent['currency'] ?? $invoice->currency)),
                reference: $id,
                meta: ['autopay' => true],
            )),
            'processing' => ChargeResult::pending(__('The bank is still processing this payment.'), $id),
            'requires_action' => ChargeResult::needsClient(__('Your bank wants you to confirm this payment yourself.')),
            default => ChargeResult::failed((string) ($intent['last_payment_error']['message'] ?? __('The card could not be charged.'))),
        };
    }

    /**
     * A saved card charge that finished after the nightly run (the bank was still processing it).
     *
     * @param  array<string, mixed>  $intent
     */
    private function resultFromSavedCharge(array $intent): ?PaymentResult
    {
        $invoice = ($intent['metadata']['autopay'] ?? null) === '1' ? Invoice::query()->find((int) ($intent['metadata']['invoice_id'] ?? 0)) : null;

        if ($invoice === null || ($intent['status'] ?? null) !== 'succeeded' || ! $this->isClientsCustomer($intent['customer'] ?? null, $invoice)) {
            return null;
        }

        return $this->resultFromIntent($intent, $invoice)->payment;
    }

    /**
     * Whether the Stripe customer is the invoice's client: the one the unclear charge went to, or
     * the owner of one of the client's saved cards.
     */
    private function isClientsCustomer(mixed $customer, Invoice $invoice): bool
    {
        if (! is_string($customer) || $customer === '') {
            return false;
        }

        return ($invoice->autopay_pending['customer'] ?? null) === $customer
            || PaymentMethod::query()->where('client_id', $invoice->client_id)->where('gateway', $this->slug())->where('customer_reference', $customer)->exists();
    }

    public function forgetSaved(PaymentMethod $method): void
    {
        $response = $this->api()->asForm()->post(self::API.'/payment_methods/'.rawurlencode($method->reference).'/detach');

        // A card Stripe no longer knows is already gone.
        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException('Stripe could not remove the saved card: '.($response->json('error.message') ?? $response->status()));
        }
    }

    /**
     * The Stripe customer the client's cards are kept under: the one used before, or a new one.
     */
    private function customerFor(Client $client): string
    {
        $known = PaymentMethod::query()->where('client_id', $client->id)->where('gateway', $this->slug())->whereNotNull('customer_reference')->latest('id')->value('customer_reference');

        if (is_string($known) && $known !== '') {
            return $known;
        }

        $response = $this->api()->asForm()->post(self::API.'/customers', [
            'email' => $client->email,
            'name' => $client->name,
            'metadata' => ['client_id' => (string) $client->id],
        ]);

        if ($response->failed() || ! is_string($response->json('id'))) {
            throw new RuntimeException('Stripe could not create a customer: '.($response->json('error.message') ?? $response->status()));
        }

        return $response->json('id');
    }

    /**
     * @param  array<string, mixed>|string|null  $paymentIntent
     */
    private function savedFromIntent(array|string|null $paymentIntent, ?string $customer): ?SavedMethod
    {
        if (is_string($paymentIntent) && str_starts_with($paymentIntent, 'pi_')) {
            $paymentIntent = $this->api()->get(self::API.'/payment_intents/'.$paymentIntent, ['expand' => ['payment_method']])->json();
        }

        if (! is_array($paymentIntent)) {
            return null;
        }

        return $this->savedFromPaymentMethod($paymentIntent['payment_method'] ?? null, $customer ?? ($paymentIntent['customer'] ?? null));
    }

    /**
     * @param  array<string, mixed>|string|null  $method
     */
    private function savedFromPaymentMethod(array|string|null $method, mixed $customer): ?SavedMethod
    {
        if (is_string($method) && str_starts_with($method, 'pm_')) {
            $method = $this->api()->get(self::API.'/payment_methods/'.$method)->json();
        }

        if (! is_array($method) || ($method['type'] ?? null) !== 'card' || ! is_string($method['id'] ?? null)) {
            return null;
        }

        $card = (array) ($method['card'] ?? []);

        return SavedMethod::card(
            $method['id'],
            is_string($customer) ? $customer : (is_string($method['customer'] ?? null) ? $method['customer'] : null),
            isset($card['brand']) ? (string) $card['brand'] : null,
            isset($card['last4']) ? (string) $card['last4'] : null,
            isset($card['exp_month']) ? (int) $card['exp_month'] : null,
            isset($card['exp_year']) ? (int) $card['exp_year'] : null,
        );
    }

    private function api(): PendingRequest
    {
        return Http::withToken((string) $this->setting('secret_key'))->timeout(30)->acceptJson();
    }
}
