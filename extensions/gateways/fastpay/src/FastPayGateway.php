<?php

namespace Nuvabill\Extensions\FastPay;

use App\Extensions\Gateways\Gateway;
use App\Extensions\Gateways\PaymentResult;
use App\Extensions\Gateways\PaymentStart;
use App\Extensions\Gateways\WebhookResult;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Support\Money;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * FastPay payment gateway. The client pays on FastPay's page. FastPay then calls the IPN address
 * set in the FastPay merchant panel, and the payment is checked with FastPay's validate call.
 */
class FastPayGateway extends Gateway
{
    public function settingsFields(): array
    {
        return [
            'display_name' => [
                'label' => 'Name shown to clients',
                'type' => 'text',
                'help' => 'Leave empty to show "FastPay".',
            ],
            'store_id' => [
                'label' => 'Store ID',
                'type' => 'text',
                'required' => true,
                'help' => 'FastPay emails the store ID and password when your merchant account is ready.',
            ],
            'store_password' => [
                'label' => 'Store password',
                'type' => 'password',
                'required' => true,
            ],
            'mode' => [
                'label' => 'Mode',
                'type' => 'select',
                'options' => ['live' => 'Live', 'staging' => 'Test (FastPay staging)'],
                'help' => 'In the FastPay merchant panel, set the IPN URL to the webhook URL below and the success URL to '.route('client.invoices.index').'.',
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
        $orderId = 'NB'.$invoice->id.'X'.Str::upper(Str::random(8));

        $response = $this->post('/api/v1/public/pgw/payment/initiation', [
            'order_id' => $orderId,
            'bill_amount' => $amount,
            'currency' => 'IQD',
            'cart' => json_encode([[
                'name' => __('Invoice :number', ['number' => $invoice->displayNumber()]),
                'qty' => 1,
                'unit_price' => $amount,
                'sub_total' => $amount,
            ]]),
        ]);

        $redirect = $response->json('data.redirect_uri');

        if ((int) $response->json('code') !== 200 || ! is_string($redirect)) {
            throw new RuntimeException('FastPay could not start the payment: '.implode(' ', (array) $response->json('messages', [$response->status()])));
        }

        PaymentIntent::create([
            'invoice_id' => $invoice->id,
            'gateway' => $this->slug(),
            'reference' => $orderId,
            'amount' => $invoice->balance(),
            'currency' => $invoice->currency,
            'status' => PaymentIntent::STATUS_PENDING,
            'meta' => $this->chargeDetails($quote, $amount * 100),
        ]);

        return PaymentStart::redirect($redirect);
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        $intent = PaymentIntent::query()
            ->where('invoice_id', $invoice->id)
            ->where('gateway', $this->slug())
            ->where('status', PaymentIntent::STATUS_PENDING)
            ->latest('id')
            ->first();

        return $intent === null ? null : $this->validate($intent);
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        $intent = PaymentIntent::findFor($this->slug(), (string) $request->input('merchant_order_id', ''));

        if ($intent === null) {
            return WebhookResult::ignored('Unknown order.');
        }

        $result = $this->validate($intent);

        return $result ? WebhookResult::paid($result) : WebhookResult::ignored('Not paid.');
    }

    /**
     * The IPN is not signed, so every payment is confirmed with FastPay's validate call.
     */
    private function validate(PaymentIntent $intent): ?PaymentResult
    {
        $response = $this->post('/api/v1/public/pgw/payment/validate', ['order_id' => $intent->reference]);

        if ((int) $response->json('code') !== 200
            || strtolower((string) $response->json('data.status')) !== 'success'
            || strtoupper((string) $response->json('data.currency', 'IQD')) !== 'IQD') {
            return null;
        }

        $result = $this->resultFor(
            $intent,
            Money::toMinor((string) $response->json('data.received_amount')),
            'IQD',
            (string) ($response->json('data.gw_transaction_id') ?: $response->json('data.transaction_id') ?: $intent->reference),
            ['customer_mobile' => $response->json('data.customer_mobile_number')],
        );

        if ($result !== null) {
            $intent->update(['status' => PaymentIntent::STATUS_PAID]);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function post(string $path, array $data): Response
    {
        $base = $this->setting('mode') === 'staging' ? 'https://staging-apigw-merchant.fast-pay.iq' : 'https://apigw-merchant.fast-pay.iq';

        return Http::timeout(30)->acceptJson()->post($base.$path, [
            'store_id' => $this->setting('store_id'),
            'store_password' => $this->setting('store_password'),
        ] + $data);
    }
}
