<?php

namespace Nuvabill\Extensions\Wayl;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Support\Money;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Wayl payment links. Each payment gets its own link and webhook; webhooks are signed with
 * HMAC-SHA256, and the link status is read again from Wayl before a payment counts.
 */
class WaylGateway extends Gateway
{
    private const API = 'https://api.thewayl.com';

    /**
     * Wayl link statuses that mean the money arrived.
     */
    private const PAID_STATUSES = ['complete', 'delivered'];

    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "Wayl". Many hosts use "Card or wallet (Wayl)".',
            ],
            'api_token' => [
                'label' => 'API token',
                'type' => 'password',
                'required' => true,
                'help' => 'Email jisr@wayl.io from your Wayl account to get an API token.',
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'test' => 'Test'],
                'help' => 'Test payments mark real invoices paid. Use Test only on a staging site. Links made in Test mode stop working once Wayl is live.',
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($currency) === 'IQD';
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $quote = $this->quote($invoice);
        $amount = $this->wholeUnits($quote['amount']);
        $reference = 'NB-'.$invoice->id.'-'.Str::lower(Str::random(10));
        $env = $this->setting('mode') === 'test' ? 'test' : 'live';

        $response = $this->api()->post(self::API.'/api/v1/links', [
            'env' => $env,
            'referenceId' => $reference,
            'total' => $amount,
            'currency' => 'IQD',
            'lineItem' => [[
                'label' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
                'amount' => $amount,
                'type' => 'increase',
            ]],
            'webhookUrl' => route('webhooks.gateway', $this->slug()),
            'webhookSecret' => $this->webhookSecret(),
            'redirectionUrl' => $returnUrl,
            'customParameter' => (string) $invoice->id,
        ]);

        $url = $response->json('data.url');

        if (! $response->successful() || ! is_string($url)) {
            $details = collect((array) $response->json('errors'))->flatten()->filter(fn ($error): bool => is_string($error))->implode('; ');

            throw new RuntimeException('Wayl could not create the payment link: '.($response->json('message') ?? $response->status()).($details !== '' ? " ({$details})" : ''));
        }

        PaymentIntent::create([
            'invoice_id' => $invoice->id,
            'gateway' => $this->slug(),
            'reference' => $reference,
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::STATUS_PENDING,
            'meta' => ['link_id' => $response->json('data.id'), 'code' => $response->json('data.code'), 'env' => $env] + $this->chargeDetails($quote, $amount * 100),
        ]);

        return PaymentStart::redirect($url);
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        $intent = PaymentIntent::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', $this->slug())
            ->where('status', PaymentIntent::STATUS_PENDING)
            ->latest('id')
            ->first();

        return $intent === null ? null : $this->confirm($intent);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $expected = hash_hmac('sha256', $request->getContent(), $this->webhookSecret());

        if (! hash_equals($expected, strtolower((string) $request->header('x-wayl-signature-256', '')))) {
            return WebhookResult::invalid('Invalid Wayl signature.');
        }

        $intent = PaymentIntent::findFor($this->slug(), (string) $request->input('referenceId', ''));

        if ($intent === null) {
            return WebhookResult::ignored('Unknown payment link.');
        }

        $result = $this->confirm($intent);

        return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Not paid yet.');
    }

    private function confirm(PaymentIntent $intent): ?PaymentResult
    {
        // Wayl uses the same address and token in both modes, so a link made in Test mode would
        // still confirm after the switch to Live. Payments on live links always count, even while
        // staff try Test mode.
        if (($intent->meta['env'] ?? null) === 'test' && $this->setting('mode') !== 'test') {
            if ($intent->status === PaymentIntent::STATUS_PENDING) {
                $intent->update(['status' => PaymentIntent::STATUS_FAILED]);
                Log::warning("Wayl test link {$intent->reference} was ignored because Wayl is live now.");
            }

            return null;
        }

        $response = $this->api()->get(self::API.'/api/v1/links/'.rawurlencode($intent->reference));

        if (! $response->successful() || ! in_array(strtolower((string) $response->json('data.status')), self::PAID_STATUSES, true)) {
            return null;
        }

        $result = $this->resultFor(
            $intent,
            Money::toMinor((string) $response->json('data.total')),
            'IQD',
            (string) ($response->json('data.id') ?: $intent->reference),
            ['payment_method' => $response->json('data.paymentMethod')],
        );

        if ($result !== null) {
            $intent->update(['status' => PaymentIntent::STATUS_PAID]);
        }

        return $result;
    }

    /**
     * A secret for this site's webhooks, made from the app key so staff never need to enter one.
     */
    private function webhookSecret(): string
    {
        return hash_hmac('sha256', 'nuvabill-wayl-webhook', (string) config('app.key'));
    }

    private function api(): PendingRequest
    {
        return Http::withHeaders(['X-WAYL-AUTHENTICATION' => (string) $this->setting('api_token')])->timeout(30)->acceptJson();
    }
}
