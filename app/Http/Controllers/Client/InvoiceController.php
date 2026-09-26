<?php

namespace App\Http\Controllers\Client;

use App\Billing\InvoicePdf;
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

    public function show(Request $request, Invoice $invoice, ExtensionManager $extensions): View
    {
        $this->authorizeOwner($request, $invoice);

        $invoice->load('items', 'transactions');

        return view('theme::client.invoices.show', [
            'invoice' => $invoice,
            'gateways' => $invoice->isPayable() ? $extensions->activeGateways($invoice->currency) : collect(),
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
