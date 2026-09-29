<?php

namespace App\Billing;

use App\Models\Invoice;
use App\Models\Quote;
use App\Support\Branding;
use App\Support\Locales;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Renders invoices and quotes as PDF files, in the client's language. Arabic, Kurdish and Chinese PDFs
 * stay in English: the PDF engine cannot join Arabic letters or draw Chinese ones.
 */
class InvoicePdf
{
    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('items', 'client', 'transactions');

        return Locales::in(Locales::forPdf(Locales::forClient($invoice->client)), fn (): string => $this->pdf(view('pdf.invoice', ['invoice' => $invoice] + $this->shared())->render()));
    }

    /**
     * @return array{company: array<string, mixed>, accent: mixed, showPoweredBy: bool}
     */
    private function shared(): array
    {
        return [
            'company' => [
                'name' => setting('company.name'),
                'email' => setting('company.email'),
                'address' => setting('company.address'),
                'phone' => setting('company.phone'),
                'tax_id' => setting('company.tax_id'),
            ],
            'accent' => setting('branding.accent'),
            'showPoweredBy' => Branding::showPoweredBy(),
        ];
    }

    private function pdf(string $html): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The same page style for a quote.
     */
    public function renderQuote(Quote $quote): string
    {
        $quote->loadMissing('items', 'client');

        return Locales::in(Locales::forPdf(Locales::forClient($quote->client)), fn (): string => $this->pdf(view('pdf.quote', ['quote' => $quote] + $this->shared())->render()));
    }

    public function quoteFilename(Quote $quote): string
    {
        return 'quote-'.preg_replace('/[^A-Za-z0-9_-]/', '', $quote->displayNumber()).'.pdf';
    }

    public function filename(Invoice $invoice): string
    {
        return 'invoice-'.preg_replace('/[^A-Za-z0-9_-]/', '', $invoice->displayNumber()).'.pdf';
    }
}
