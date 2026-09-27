<?php

namespace Nuvabill\Extensions\Fib;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * FIB online payments. A payment shows a QR code and a short code the client enters in the FIB app.
 * FIB's status notification is not signed, so the status is always read again from FIB before it counts.
 */
class FibGateway extends Gateway
{
    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "FIB (First Iraqi Bank)".',
            ],
            'client_id' => [
                'label' => 'Client ID',
                'type' => 'text',
                'required' => true,
                'help' => 'FIB sends the client ID and secret after you apply with the FIB integration request form.',
            ],
            'client_secret' => [
                'label' => 'Client secret',
                'type' => 'password',
                'required' => true,
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'stage' => 'Test (FIB stage)'],
            ],
        ];
    }

    public function supportsCurrency(string $currency): bool
    {
        return strtoupper($currency) === 'IQD';
    }

    public function checksWhileWaiting(): bool
    {
        return true;
    }

    public function startPayment(Invoice $invoice, string $returnUrl, string $cancelUrl): PaymentStart
    {
        $intent = PaymentIntent::latestOpenFor($invoice, $this->slug()) ?? $this->createPayment($invoice);

        return PaymentStart::qr(
            (string) $intent->meta['qr_code'],
            $intent->meta['readable_code'] ?? null,
            array_filter([
                __('Open FIB Personal') => $intent->meta['personal_app_link'] ?? null,
                __('Open FIB Business') => $intent->meta['business_app_link'] ?? null,
            ]),
            $intent->expires_at?->toIso8601String(),
        );
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
        $intent = PaymentIntent::findFor($this->slug(), (string) $request->input('id', ''));

        if ($intent === null) {
            return WebhookResult::ignored('Unknown payment.');
        }

        $result = $this->confirm($intent);

        return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Not paid yet.');
    }

    /**
     * Ask FIB for the payment's status. A paid payment becomes a result; a declined one is closed.
     */
    private function confirm(PaymentIntent $intent): ?PaymentResult
    {
        $response = $this->api()->get($this->baseUrl().'/protected/v1/payments/'.rawurlencode($intent->reference).'/status');

        if (! $response->successful()) {
            return null;
        }

        $status = strtoupper((string) $response->json('status'));

        if ($status === 'DECLINED') {
            $intent->update(['status' => PaymentIntent::STATUS_FAILED, 'meta' => $intent->meta + ['declined' => $response->json('decliningReason')]]);

            return null;
        }

        if ($status !== 'PAID' || strtoupper((string) $response->json('amount.currency', 'IQD')) !== 'IQD') {
            return null;
        }

        $result = $this->resultFor($intent, (int) round((float) $response->json('amount.amount', 0) * 100), 'IQD', $intent->reference, ['paid_by' => $response->json('paidBy.name')]);

        if ($result !== null) {
            $intent->update(['status' => PaymentIntent::STATUS_PAID]);
        }

        return $result;
    }

    private function createPayment(Invoice $invoice): PaymentIntent
    {
        $quote = $this->quote($invoice);
        $amount = $this->wholeUnits($quote['amount']);

        $response = $this->api()->post($this->baseUrl().'/protected/v1/payments', [
            'monetaryValue' => ['amount' => $amount, 'currency' => 'IQD'],
            'statusCallbackUrl' => route('webhooks.gateway', $this->slug()),
            'description' => mb_substr(__('Invoice :number', ['number' => $invoice->displayNumber()]), 0, 50),
            'refundableFor' => 'P7D',
        ]);

        if (! $response->successful() || ! is_string($response->json('paymentId'))) {
            throw new RuntimeException('FIB could not start the payment: HTTP '.$response->status().' '.$response->body());
        }

        return PaymentIntent::create([
            'invoice_id' => $invoice->id,
            'gateway' => $this->slug(),
            'reference' => $response->json('paymentId'),
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::STATUS_PENDING,
            'expires_at' => $response->json('validUntil') ? Carbon::parse($response->json('validUntil')) : now()->addMinutes(15),
            'meta' => [
                'qr_code' => $response->json('qrCode'),
                'readable_code' => $response->json('readableCode'),
                'personal_app_link' => $response->json('personalAppLink'),
                'business_app_link' => $response->json('businessAppLink'),
            ] + $this->chargeDetails($quote, $amount * 100),
        ]);
    }

    private function api(): PendingRequest
    {
        return Http::withToken($this->accessToken())->timeout(30)->acceptJson();
    }

    /**
     * FIB tokens last about a minute, so one is kept for 50 seconds.
     */
    private function accessToken(): string
    {
        $key = 'nuvabill.fib.token.'.md5($this->baseUrl().$this->setting('client_id'));

        return Cache::remember($key, 50, function (): string {
            $response = Http::asForm()
                ->withBasicAuth((string) $this->setting('client_id'), (string) $this->setting('client_secret'))
                ->timeout(30)
                ->post($this->baseUrl().'/auth/realms/fib-online-shop/protocol/openid-connect/token', ['grant_type' => 'client_credentials']);

            if (! $response->successful() || ! is_string($response->json('access_token'))) {
                throw new RuntimeException('FIB refused the client ID or secret (HTTP '.$response->status().').');
            }

            return $response->json('access_token');
        });
    }

    private function baseUrl(): string
    {
        return $this->setting('mode') === 'stage' ? 'https://fib.stage.fib.iq' : 'https://fib.prod.fib.iq';
    }
}
