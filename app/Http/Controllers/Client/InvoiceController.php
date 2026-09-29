<?php

namespace App\Http\Controllers\Client;

use App\Billing\AutoPay;
use App\Billing\InvoicePdf;
use App\Billing\SavedMethods;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        return view('theme::client.invoices.index', [
            'invoices' => $request->user('web')->invoices()
                ->where('status', '!=', InvoiceStatus::Draft)
                ->latest('id')
                ->paginate(20),
        ]);
    }

    public function show(Request $request, Invoice $invoice, ExtensionManager $extensions, AutoPay $autoPay, SavedMethods $methods): View
    {
        $this->authorizeOwner($request, $invoice);

        $invoice->load('items', 'transactions');
        $autoMethod = $invoice->isPayable() ? $autoPay->methodFor($invoice) : null;

        return view('theme::client.invoices.show', [
            'invoice' => $invoice,
            'gateways' => $invoice->isPayable() ? $extensions->activeGateways($invoice->currency) : collect(),
            // Gateways that can keep the method for automatic renewals, for "Save it" on the payment form.
            'savable' => $invoice->isPayable() && $autoPay->isOn() && setting('billing.autopay_offer_save') && ! app(Wallet::class)->isTopUp($invoice)
                ? $methods->gateways($invoice->currency)->keys()->values()->all()
                : [],
            'autoPay' => $autoMethod === null ? null : [
                'method' => $autoMethod,
                'date' => $autoPay->chargeDate($invoice),
                'failed' => $invoice->autopay_attempts > 0,
                'retries' => $autoPay->willRetry($invoice),
                // The wallet is used first, so it pays the whole invoice when it holds enough.
                'wallet' => app(Wallet::class)->enabled() && $invoice->client->credit >= $invoice->balance(),
            ],
            'instructions' => session('payment_instructions'),
            'qr' => session('payment_qr'),
        ]);
    }

    public function pdf(Request $request, Invoice $invoice, InvoicePdf $pdf): Response
    {
        $this->authorizeOwner($request, $invoice);

        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf->filename($invoice).'"',
        ]);
    }

    private function authorizeOwner(Request $request, Invoice $invoice): void
    {
        abort_unless($invoice->client_id === $request->user('web')->id && $invoice->status !== InvoiceStatus::Draft, 404);
    }
}
