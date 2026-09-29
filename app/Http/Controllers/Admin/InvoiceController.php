<?php

namespace App\Http\Controllers\Admin;

use App\Billing\AutoPay;
use App\Billing\InvoiceManager;
use App\Billing\InvoicePdf;
use App\Billing\PaymentRecorder;
use App\Billing\RefundIssuer;
use App\Billing\SavedMethods;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Http\Controllers\Controller;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
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
        $invoice->load('items.service.product', 'client', 'transactions');

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

    public function store(Request $request, InvoiceManager $invoices, TemplateMailer $mailer): RedirectResponse
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
        ]);

        $client = ctype_digit($data['client'])
            ? Client::find((int) $data['client'])
            : Client::query()->where('email', $data['client'])->first();

        if ($client === null) {
            throw ValidationException::withMessages(['client' => __('No client has that ID or email.')]);
        }

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

        if ($invoice->status === InvoiceStatus::Unpaid && $request->boolean('send_email')) {
            $mailer->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));
        }

        return redirect()->route('admin.invoices.show', $invoice)->with('status', __('Invoice created.'));
    }

    public function recordPayment(Request $request, Invoice $invoice, PaymentRecorder $payments): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:190'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
        ]);

        if ($invoice->status !== InvoiceStatus::Unpaid) {
            return back()->with('error', __('Only unpaid invoices can take a payment.'));
        }

        $payments->record(
            $invoice,
            Money::toMinor($data['amount']),
            $data['method'],
            $data['reference'] ?: null,
            paidAt: CarbonImmutable::parse($data['paid_at'])->setTimeFrom(now()),
        );

        return back()->with('status', __('Payment recorded.'));
    }

    public function refund(Request $request, Invoice $invoice, RefundIssuer $refunds): RedirectResponse
    {
        if ($invoice->status !== InvoiceStatus::Paid) {
            return back()->with('error', __('Only paid invoices can be refunded.'));
        }

        try {
            $refunds->refund($invoice, $request->boolean('through_gateway'));
        } catch (RuntimeException $exception) {
            report($exception);

            return back()->with('error', __('The refund did not finish: :reason', ['reason' => $exception->getMessage()]));
        }

        return back()->with('status', __('Invoice refunded.'));
    }

    public function publish(Invoice $invoice, InvoiceManager $invoices): RedirectResponse
    {
        $invoices->publish($invoice);

        return back()->with('status', __('Invoice published. The client can now see and pay it.'));
    }

    public function cancel(Invoice $invoice, InvoiceManager $invoices): RedirectResponse
    {
        $invoices->cancel($invoice);

        return back()->with('status', __('Invoice cancelled.'));
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
