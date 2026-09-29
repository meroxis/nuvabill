<?php

namespace App\Http\Controllers\Admin;

use App\Billing\InvoicePdf;
use App\Http\Controllers\Controller;
use App\Models\CreditNote;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Every credit note, newest first, and each one as a PDF.
 */
class CreditNoteController extends Controller
{
    public function index(Request $request): View
    {
        $creditNotes = CreditNote::query()
            ->with(['client', 'invoice'])
            ->when($request->query('q'), fn ($query, string $term) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('invoice', fn ($query) => $query->where('number', 'like', "%{$term}%"))
                ->orWhereHas('client', fn ($query) => $query->search($term))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.credit-notes.index', ['creditNotes' => $creditNotes]);
    }

    public function pdf(CreditNote $creditNote, InvoicePdf $pdf): Response
    {
        return response($pdf->renderCreditNote($creditNote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->creditNoteFilename($creditNote).'"',
        ]);
    }
}
