<?php

namespace App\Http\Controllers\Client;

use App\Billing\PaymentRecorder;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class PaymentController extends Controller
{
    public function store(Request $request, Invoice $invoice, ExtensionManager $extensions): RedirectResponse
    {
        abort_unless($invoice->client_id === $request->user('web')->id, 404);

        if (! $invoice->isPayable()) {
            return redirect()->route('client.invoices.show', $invoice)->with('status', __('This invoice is already paid.'));
        }

        $gateways = $extensions->activeGateways($invoice->currency);
        $slug = (string) $request->validate(['gateway' => ['required', 'string']])['gateway'];
        $gateway = $gateways->get($slug);

        if ($gateway === null) {
            return back()->with('error', __('Choose one of the payment methods shown.'));
        }

        $invoice->update(['payment_method' => $slug]);

        try {
            $start = $gateway->startPayment(
                $invoice->loadMissing('client'),
                route('client.invoices.return', [$invoice, $slug]),
                route('client.invoices.show', $invoice),
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __(':gateway is not available right now. Try another payment method or contact us.', ['gateway' => $gateway->name()]));
        }

        if ($start->isRedirect()) {
            return redirect()->away($start->redirectUrl);
        }

        return redirect()->route('client.invoices.show', $invoice)->with('payment_instructions', Str::markdown((string) $start->instructions, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]));
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

            return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thank you! Your payment was received.'));
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thanks! We will mark the invoice paid as soon as the payment is confirmed.'));
    }
}
