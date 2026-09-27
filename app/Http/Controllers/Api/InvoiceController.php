<?php

namespace App\Http\Controllers\Api;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\InvoiceResource;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Invoices in the API. Amounts are in cents (minor units), like everywhere in the API.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $invoices = Invoice::query()
            ->when($request->integer('client_id'), fn ($query, $clientId) => $query->where('client_id', $clientId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->orderByDesc('id')
            ->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return InvoiceResource::collection($invoices);
    }

    public function show(Invoice $invoice): InvoiceResource
    {
        return new InvoiceResource($invoice->load('items', 'transactions'));
    }

    public function store(Request $request, InvoiceManager $invoices, TemplateMailer $mailer): JsonResponse
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.amount' => ['required', 'integer', 'min:-100000000', 'max:100000000'],
            'items.*.taxed' => ['sometimes', 'boolean'],
            'draft' => ['sometimes', 'boolean'],
            'send_email' => ['sometimes', 'boolean'],
        ]);

        $client = Client::findOrFail($data['client_id']);
        $invoice = $invoices->create(
            $client,
            array_map(fn (array $item): array => array_filter([
                'description' => $item['description'],
                'amount' => (int) $item['amount'],
                'taxed' => array_key_exists('taxed', $item) ? (bool) $item['taxed'] : null,
            ], fn ($value) => $value !== null), array_values($data['items'])),
            dueAt: isset($data['due_at']) ? CarbonImmutable::parse($data['due_at']) : null,
            status: $request->boolean('draft') ? InvoiceStatus::Draft : InvoiceStatus::Unpaid,
            notes: $data['notes'] ?? null,
        );

        if ($invoice->status === InvoiceStatus::Unpaid && $request->boolean('send_email')) {
            $mailer->send('invoice.created', $client, TemplateMailer::invoiceContext($invoice));
        }

        return (new InvoiceResource($invoice->load('items')))->response()->setStatusCode(201);
    }

    /**
     * Record money received, for example a bank transfer. The same gateway and reference are
     * only recorded once, so retrying is safe.
     */
    public function payment(Request $request, Invoice $invoice, PaymentRecorder $payments): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'gateway' => ['required', 'string', 'max:64', Rule::notIn(['credit'])],
            'reference' => ['required', 'string', 'max:190'],
            'fee' => ['nullable', 'integer', 'min:0'],
        ]);

        if (! in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Paid], true)) {
            return response()->json(['message' => __('Payments can only be added to unpaid invoices.')], 422);
        }

        $payments->record($invoice, (int) $data['amount'], $data['gateway'], $data['reference'], (int) ($data['fee'] ?? 0));

        return (new InvoiceResource($invoice->fresh()->load('items', 'transactions')))->response();
    }
}
