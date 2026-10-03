<?php

namespace App\Http\Controllers\Admin;

use App\Billing\AutoPay;
use App\Billing\CreditNotes;
use App\Billing\InvoiceManager;
use App\Billing\InvoicePdf;
use App\Billing\PaymentRecorder;
use App\Billing\RefundIssuer;
use App\Billing\SavedMethods;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Http\Controllers\Controller;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;
use RuntimeException;

class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        $filter = (string) $request->query('status', 'all');

        $invoices = Invoice::query()
            ->with('client')
            ->when($filter === 'overdue', fn ($query) => $query->where('status', InvoiceStatus::Unpaid)->whereDate('due_at', '<', today()))
            ->when(InvoiceStatus::tryFrom($filter), fn ($query, InvoiceStatus $status) => $query->where('status', $status))
            ->when($request->query('q'), fn ($query, string $term) => $query->where(fn ($query) => $query
                ->where('number', 'like', "%{$term}%")
                ->orWhereHas('client', fn ($query) => $query->search($term))))
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('admin.invoices.index', ['invoices' => $invoices, 'filter' => $filter]);
    }

    public function show(Invoice $invoice, ExtensionManager $extensions, RefundIssuer $refunds, AutoPay $autoPay, SavedMethods $savedMethods): View
    {
        $invoice->load('items.service.product', 'client', 'transactions', 'creditNotes');

        $methods = $extensions->ofType(ExtensionManifest::TYPE_GATEWAY)
            ->map(fn (ExtensionManifest $manifest): string => $manifest->name)
            ->put('manual', __('Other (cash, cheque, ...)'))
            ->all();

        $canRefundThroughGateway = $invoice->status === InvoiceStatus::Paid
            && $invoice->transactions->where('type', 'payment')->contains(fn (Transaction $payment): bool => $refunds->canRefundThroughGateway($payment));

        $savedMethod = $invoice->isPayable() ? $invoice->client?->defaultPaymentMethod() : null;

        return view('admin.invoices.show', [
            'invoice' => $invoice,
            'methods' => $methods,
            'canRefundThroughGateway' => $canRefundThroughGateway,
            // Funds added to the wallet are already in it, so a credit note cannot put them there again.
            'creditToWallet' => $invoice->currency === $invoice->client->currency && ! $invoice->items->contains('type', InvoiceItem::TYPE_CREDIT),
            'autoPay' => $savedMethod === null ? null : [
                'method' => $savedMethod,
                'usable' => $savedMethods->gateway($savedMethod->gateway) !== null && ! $savedMethod->isExpired(),
                'automatic' => $autoPay->methodFor($invoice) !== null,
                'date' => $autoPay->chargeDate($invoice),
                'retries' => $autoPay->willRetry($invoice),
            ],
        ]);
    }

    public function create(Request $request): View
    {
        return view('admin.invoices.create', [
            'client' => Client::find($request->integer('client')),
        ]);
    }

    public function store(Request $request, InvoiceManager $invoices, TemplateMailer $mailer, PaymentRecorder $payments): RedirectResponse
    {
        $data = $request->validate([
            'client' => ['required', 'string', 'max:190'],
            'due_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'numeric', 'min:-1000000', 'max:1000000'],
            'items.*.taxed' => ['sometimes', 'boolean'],
            'draft' => ['sometimes', 'boolean'],
            'send_email' => ['sometimes', 'boolean'],
            'currency' => ['sometimes', 'nullable', 'string', 'size:3'],
        ]);

        $client = ctype_digit($data['client'])
            ? Client::find((int) $data['client'])
            : Client::query()->where('email', $data['client'])->first();

        if ($client === null) {
            throw ValidationException::withMessages(['client' => __('No client has that ID or email.')]);
        }

        // The amounts were typed next to the currency the form showed; the invoice uses the client's.
        if (filled($data['currency'] ?? null) && strtoupper($data['currency']) !== $client->currency) {
            return redirect()->route('admin.invoices.create', ['client' => $client->id])->withInput()->withErrors([
                'client' => __('This client is billed in :currency. Check the amounts and submit again.', ['currency' => $client->currency]),
            ]);
        }

        // Lines below zero are discounts; the invoice as a whole cannot be. Such an invoice is rolled back.
        $invoice = DB::transaction(function () use ($invoices, $client, $data, $request): Invoice {
            $invoice = $invoices->create(
                $client,
                array_map(fn (array $item): array => [
                    'description' => $item['description'],
                    'amount' => Money::toMinor($item['amount']),
                    'taxed' => (bool) ($item['taxed'] ?? true),
                ], array_values($data['items'])),
                dueAt: CarbonImmutable::parse($data['due_at']),
                status: $request->boolean('draft') ? InvoiceStatus::Draft : InvoiceStatus::Unpaid,
                notes: $data['notes'] ?? null,
            );

            if ($invoice->total < 0) {
                throw ValidationException::withMessages(['items' => __('The invoice total cannot be below zero.')]);
            }

            return $invoice;
        });

        if ($invoice->status === InvoiceStatus::Unpaid && $invoice->total === 0) {
            // Nothing to pay: it is settled now, like a free renewal, instead of waiting as unpaid.
            $payments->settleFreeInvoice($invoice);
        } elseif ($invoice->status === InvoiceStatus::Unpaid && $request->boolean('send_email')) {
            $mailer->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', __('Invoice created.'));
    }

    public function recordPayment(Request $request, Invoice $invoice, PaymentRecorder $payments, ExtensionManager $extensions): RedirectResponse
    {
        // The methods the form offers. Wallet payments are made from the wallet, never typed in.
        $methods = $extensions->ofType(ExtensionManifest::TYPE_GATEWAY)->keys()->push('manual')->reject(fn (string $method): bool => $method === Wallet::GATEWAY);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', Rule::in($methods->all())],
            'reference' => ['nullable', 'string', 'max:190'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        if ($invoice->status !== InvoiceStatus::Unpaid) {
            return back()->with('error', __('Only unpaid invoices can take a payment.'));
        }

        $transaction = $payments->record(
            $invoice,
            Money::toMinor($data['amount']),
            $data['method'],
            $data['reference'] ?: null,
            paidAt: CarbonImmutable::parse($data['paid_at'])->setTimeFrom(now()),
        );

        // The same method and reference are recorded once; an earlier payment with them was found instead.
        if (! $transaction->wasRecentlyCreated || $transaction->invoice_id !== $invoice->id) {
            return back()->withInput()->with('error', __('Reference :reference was already recorded on invoice :number. Use a different reference.', [
                'reference' => $transaction->reference,
                'number' => $transaction->invoice?->displayNumber() ?? '—',
            ]));
        }

        return back()->with('status', __('Payment recorded.'));
    }

    /**
     * Refund the whole invoice (what no credit note took back yet), with a credit note for it.
     */
    public function refund(Request $request, Invoice $invoice, CreditNotes $creditNotes): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            return back()->with('error', __('Only paid invoices can be refunded.'));
        }

        try {
            $creditNote = $creditNotes->issue($invoice, $invoice->creditableAmount(), CreditNote::METHOD_REFUND, __('Refund'), $request->user('admin'), $request->boolean('through_gateway'));
        } catch (RuntimeException|InvalidArgumentException $exception) {
            report($exception);

            return back()->with('error', __('The refund did not finish: :reason', ['reason' => $exception->getMessage()]));
        }

        return back()->with('status', __('Invoice refunded. Credit note :number was made.', ['number' => $creditNote->number]));
    }

    /**
     * Take back part or all of a paid invoice: the money goes back, into the wallet, or nowhere.
     */
    public function creditNote(Request $request, Invoice $invoice, CreditNotes $creditNotes): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999'],
            'method' => ['required', Rule::in(CreditNote::METHODS)],
            'reason' => ['nullable', 'string', 'max:500'],
            'through_gateway' => ['boolean'],
        ]);

        try {
            $creditNote = $creditNotes->issue($invoice, Money::toMinor((string) $data['amount']), $data['method'], $data['reason'] ?? null, $request->user('admin'), $request->boolean('through_gateway'));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->with('error', __('The refund did not finish: :reason', ['reason' => $exception->getMessage()]));
        }

        return back()->with('status', __('Credit note :number was made.', ['number' => $creditNote->number]));
    }

    public function publish(Invoice $invoice, InvoiceManager $invoices, PaymentRecorder $payments): RedirectResponse
    {
        $invoices->publish($invoice);

        // A draft that comes to nothing is settled at once instead of waiting as unpaid.
        if ($invoice->status === InvoiceStatus::Unpaid && $invoice->total === 0) {
            $payments->settleFreeInvoice($invoice);
        }

        return back()->with('status', __('Invoice published. The client can now see and pay it.'));
    }

    /**
     * Cancel an unpaid or draft invoice. Money already paid on it goes back to the client's wallet.
     */
    public function cancel(Invoice $invoice, InvoiceManager $invoices): RedirectResponse
    {
        $paid = $invoice->amount_paid;

        try {
            $invoices->cancel($invoice);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        // It was paid or closed in the meantime, for example by a payment that just arrived.
        if ($invoice->status !== InvoiceStatus::Cancelled) {
            return back()->with('error', __('Only unpaid invoices can be cancelled.'));
        }

        return back()->with('status', $paid > 0
            ? __('Invoice cancelled. The :amount paid on it went back to the client\'s wallet.', ['amount' => money($paid, $invoice->currency)])
            : __('Invoice cancelled.'));
    }

    public function email(Invoice $invoice, TemplateMailer $mailer): RedirectResponse
    {
        $sent = $mailer->send(
            $invoice->status === InvoiceStatus::Unpaid && $invoice->isOverdue() ? 'invoice.reminder' : 'invoice.created',
            $invoice->client,
            TemplateMailer::invoiceContext($invoice),
        );

        return back()->with($sent ? 'status' : 'error', $sent ? __('Email sent to :email.', ['email' => $invoice->client->email]) : __('The email could not be sent. Check Settings → Email.'));
    }

    public function pdf(Invoice $invoice, InvoicePdf $pdf): Response
    {
        return response($pdf->render($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$pdf->filename($invoice).'"',
        ]);
    }
}
