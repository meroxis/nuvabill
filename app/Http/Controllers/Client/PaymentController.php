<?php

namespace App\Http\Controllers\Client;

use App\Billing\PaymentRecorder;
use App\Billing\PaymentStarter;
use App\Extensions\ExtensionManager;
use App\Extensions\Gateways\Gateway;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Throwable;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice, PaymentStarter $payments): RedirectResponse
    {
        abort_unless($invoice->client_id === $request->user('web')->id, 404);

        if (! $invoice->isPayable()) {
            return redirect()->route('client.invoices.show', $invoice)->with('status', __('This invoice is already paid.'));
        }

        return $payments->start($invoice, (string) $request->validate(['gateway' => ['required', 'string']])['gateway'], $request->boolean('save_method'));
    }

    /**
     * Polled by the invoice page while the client pays in another app. Asks the gateway at most
     * every few seconds, so a waiting page cannot flood it.
     */
    public function status(Request $request, Invoice $invoice, ExtensionManager $extensions, PaymentRecorder $payments): JsonResponse
    {
        abort_unless($invoice->client_id === $request->user('web')->id, 404);

        $gateway = $invoice->payment_method ? $extensions->activeGateways()->get($invoice->payment_method) : null;
        $received = false;

        if ($invoice->isPayable() && $gateway instanceof Gateway && $gateway->checksWhileWaiting() && Cache::add('nuvabill.payment-poll.'.$invoice->id, true, 8)) {
            try {
                $result = $gateway->handleReturn($request, $invoice);
            } catch (Throwable $exception) {
                report($exception);
                $result = null;
            }

            if ($result !== null) {
                $payments->recordGatewayResult($result, $invoice->payment_method);
                $invoice->refresh();
                // A payment that left a part to pay (the exchange rate changed): the page shows the new balance.
                $received = $invoice->isPayable();
            }
        }

        return response()->json(['paid' => ! $invoice->isPayable()] + ($received ? ['received' => true] : []));
    }

    public function return(Request $request, Invoice $invoice, string $gateway, ExtensionManager $extensions, PaymentRecorder $payments): RedirectResponse
    {
        abort_unless($invoice->client_id === $request->user('web')->id, 404);

        $instance = $extensions->activeGateways()->get($gateway);

        if ($instance === null) {
            return redirect()->route('client.invoices.show', $invoice);
        }

        try {
            $result = $instance->handleReturn($request, $invoice);
        } catch (Throwable $exception) {
            report($exception);
            $result = null;
        }

        if ($result !== null) {
            $payments->recordGatewayResult($result, $gateway);

            // A payment started at an older exchange rate can leave a part of the invoice to pay.
            if (isset($result->meta['quoted_amount']) && $invoice->refresh()->isPayable()) {
                return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thank you! Your payment was received. The exchange rate changed after you started it, so :amount is still to pay.', ['amount' => money($invoice->balance(), $invoice->currency)]));
            }

            return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thank you! Your payment was received.'));
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thanks! We will mark the invoice paid as soon as the payment is confirmed.'));
    }
}
