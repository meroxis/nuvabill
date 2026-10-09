<?php

namespace App\Http\Controllers\Client;

use App\Billing\InvoicePdf;
use App\Billing\QuoteManager;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Quote;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use RuntimeException;

/**
 * Quotes in the client area: read them, then accept or decline.
 */
class QuoteController extends Controller
{
    public function index(Request $request): View
    {
        return view('theme::client.quotes.index', [
            'quotes' => $request->user('web')->quotes()->where('status', '!=', QuoteStatus::Draft)->latest('id')->paginate(20),
        ]);
    }

    public function show(Request $request, Quote $quote): View
    {
        $this->authorizeQuote($request, $quote);

        return view('theme::client.quotes.show', ['quote' => $quote->load('items', 'invoice')]);
    }

    public function accept(Request $request, Quote $quote, QuoteManager $quotes): RedirectResponse
    {
        $this->authorizeQuote($request, $quote);

        try {
            $invoice = $quotes->accept($quote);
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        return redirect()->route('client.invoices.show', $invoice)->with('status', __('Thank you! Your invoice is ready.'));
    }

    public function decline(Request $request, Quote $quote, QuoteManager $quotes): RedirectResponse
    {
        $this->authorizeQuote($request, $quote);

        try {
            $quotes->decline($quote);
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        return back()->with('status', __('Quote declined. Thank you for letting us know.'));
    }

    public function pdf(Request $request, Quote $quote, InvoicePdf $pdf): Response
    {
        $this->authorizeQuote($request, $quote);

        return response($pdf->renderQuote($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->quoteFilename($quote).'"',
        ]);
    }

    /**
     * Clients only see their own quotes, and never drafts.
     */
    private function authorizeQuote(Request $request, Quote $quote): void
    {
        abort_unless($quote->client_id === $request->user('web')->id && $quote->status !== QuoteStatus::Draft, 404);
    }
}
