<?php

namespace App\Billing;

use App\Extensions\ExtensionManager;
use App\Models\Invoice;
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

    public function start(Invoice $invoice, string $slug): RedirectResponse
    {
        $gateway = $this->extensions->activeGateways($invoice->currency)->get($slug);

        if ($gateway === null) {
            return redirect()->route('client.invoices.show', $invoice)->with('error', __('Choose one of the payment methods shown.'));
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
