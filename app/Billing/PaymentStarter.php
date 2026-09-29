<?php

namespace App\Billing;

use App\Contracts\SavesPaymentMethods;
use App\Extensions\ExtensionManager;
use App\Models\Invoice;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts paying an invoice with a gateway and sends the client where they need to go: the
 * gateway's page, a QR code, or instructions on the invoice page.
 */
class PaymentStarter
{
    public function __construct(private ExtensionManager $extensions) {}

    /**
     * @param  bool  $save  The client ticked "Save it and pay my renewals automatically".
     */
    public function start(Invoice $invoice, string $slug, bool $save = false): RedirectResponse
    {
        $gateway = $this->extensions->activeGateways($invoice->currency)->get($slug);

        if ($gateway === null) {
            return redirect()->route('client.invoices.show', $invoice)->with('error', __('Choose one of the payment methods shown.'));
        }

        $invoice->update(['payment_method' => $slug]);

        try {
            $save = $save && setting('billing.autopay_offer_save') && $gateway instanceof SavesPaymentMethods;
            $returnUrl = route('client.invoices.return', [$invoice, $slug]);
            $cancelUrl = route('client.invoices.show', $invoice);
            $start = $save
                ? $gateway->startSavingPayment($invoice->loadMissing('client'), $returnUrl, $cancelUrl)
                : $gateway->startPayment($invoice->loadMissing('client'), $returnUrl, $cancelUrl);
        } catch (Throwable $exception) {
            report($exception);
            // Staff see the gateway's own reason in the activity log, without reading server logs.
            Activity::log('payment.failed', "{$gateway->name()} could not start a payment for invoice {$invoice->displayNumber()}: ".Str::limit($exception->getMessage(), 300), $invoice, $invoice->client);

            return redirect()->route('client.invoices.show', $invoice)->with('error', __(':gateway is not available right now. Try another payment method or contact us.', ['gateway' => $gateway->name()]));
        }

        if ($start->isRedirect()) {
            return redirect()->away($start->redirectUrl);
        }

        if ($start->isQr()) {
            return redirect()->route('client.invoices.show', $invoice)->with('payment_qr', [
                'gateway' => $gateway->name(),
                'image' => $start->qrImage,
                'code' => $start->code,
                'links' => $start->appLinks,
                'expires_at' => $start->expiresAt,
            ]);
        }

        return redirect()->route('client.invoices.show', $invoice)->with('payment_instructions', Str::markdown((string) $start->instructions, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]));
    }
}
