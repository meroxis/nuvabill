<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Support\Branding;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders an invoice as a PDF file.
 */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('items', 'client', 'transactions');

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'company' => [
                'name' => setting('company.name'),
                'email' => setting('company.email'),
                'address' => setting('company.address'),
                'phone' => setting('company.phone'),
                'tax_id' => setting('company.tax_id'),
            ],
            'accent' => setting('branding.accent'),
            'showPoweredBy' => Branding::showPoweredBy(),
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '', $invoice->displayNumber()).'.pdf';
    }
}
