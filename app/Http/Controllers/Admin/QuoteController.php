<?php

namespace App\Http\Controllers\Admin;

use App\Billing\InvoicePdf;
use App\Billing\QuoteManager;
use App\Enums\QuoteStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Quote;
use App\Support\Activity;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;

/**
 * Quotes: write a price offer, send it, and see when the client accepts it.
 */
class QuoteController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');

        return view('admin.quotes.index', [
            'quotes' => Quote::query()
                ->with('client')
                ->when(in_array($status, ['draft', 'sent', 'accepted', 'declined'], true), fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.quotes.form', [
            'quote' => new Quote(['valid_until' => today()->addDays((int) setting('quotes.valid_days'))]),
            'client' => $request->integer('client') ? Client::find($request->integer('client')) : null,
            'items' => [['description' => '', 'amount' => '', 'taxed' => true]],
        ]);
    }

    public function store(Request $request, QuoteManager $quotes): RedirectResponse
    {
        [$client, $data, $items] = $this->validated($request);
        $quote = $quotes->save(null, $client, $data, $items);
        Activity::log('quote.created', "Quote for {$client->name} created: {$quote->subject}", $quote, $client);

        return $this->afterSave($request, $quote, $quotes, __('Quote saved.'));
    }

    public function show(Quote $quote): View
    {
        return view('admin.quotes.show', ['quote' => $quote->load('items', 'client', 'invoice')]);
    }

    public function edit(Quote $quote): View|RedirectResponse
    {
        if (! $quote->isEditable()) {
            return redirect()->route('admin.quotes.show', $quote)->with('error', __('Accepted or declined quotes cannot be changed.'));
        }

        return view('admin.quotes.form', [
            'quote' => $quote,
            'client' => $quote->client,
            'items' => $quote->items->map(fn ($item): array => ['description' => $item->description, 'amount' => Money::toDecimal($item->amount), 'taxed' => $item->taxed || $quote->tax_rate === null])->all(),
        ]);
    }

    public function update(Request $request, Quote $quote, QuoteManager $quotes): RedirectResponse
    {
        [$client, $data, $items] = $this->validated($request);

        try {
            $quotes->save($quote, $client, $data, $items);
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        return $this->afterSave($request, $quote, $quotes, __('Quote saved.'));
    }

    public function send(Quote $quote, QuoteManager $quotes): RedirectResponse
    {
        try {
            $quotes->send($quote);
        } catch (RuntimeException $exception) {
            return back()->with('error', $this->refusalMessage($exception));
        }

        return back()->with('status', __('Quote sent to :email.', ['email' => $quote->client->email]));
    }

    public function destroy(Quote $quote): RedirectResponse
    {
        if ($quote->status !== QuoteStatus::Draft) {
            return back()->with('error', __('Only draft quotes can be deleted.'));
        }

        $quote->delete();
        Activity::log('quote.deleted', "Draft quote {$quote->subject} deleted", client: $quote->client);

        return redirect()->route('admin.quotes.index')->with('status', __('Draft deleted.'));
    }

    public function pdf(Quote $quote, InvoicePdf $pdf): Response
    {
        return response($pdf->renderQuote($quote), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->quoteFilename($quote).'"',
        ]);
    }

    private function afterSave(Request $request, Quote $quote, QuoteManager $quotes, string $message): RedirectResponse
    {
        if ($request->boolean('send')) {
            $quotes->send($quote);
            $message = __('Quote sent to :email.', ['email' => $quote->client->email]);
        }

        return redirect()->route('admin.quotes.show', $quote)->with('status', $message);
    }

    /**
     * @return array{0: Client, 1: array<string, mixed>, 2: list<array{description: string, amount: int, taxed: bool}>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'max:190'],
            'subject' => ['required', 'string', 'max:190'],
            'valid_until' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
            'items.*.taxed' => ['sometimes', 'boolean'],
            'send' => ['sometimes', Rule::in(['0', '1'])],
        ]);

        $client = ctype_digit($data['client'])
            ? Client::find((int) $data['client'])
            : Client::query()->where('email', $data['client'])->first();

        if ($client === null) {
            throw ValidationException::withMessages(['client' => __('No client has that ID or email.')]);
        }

        return [
            $client,
            [
                'subject' => $data['subject'],
                'valid_until' => CarbonImmutable::parse($data['valid_until']),
                'notes' => $data['notes'] ?? null,
                'admin_notes' => $data['admin_notes'] ?? null,
            ],
            array_map(fn (array $item): array => [
                'description' => $item['description'],
                'amount' => Money::toMinor($item['amount']),
                'taxed' => (bool) ($item['taxed'] ?? true),
            ], array_values($data['items'])),
        ];
    }
}
