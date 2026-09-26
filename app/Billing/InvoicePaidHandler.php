<?php

namespace App\Billing;

use App\Enums\AutoSetup;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Jobs\ProvisionService;
use App\Mail\TemplateMailer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use App\Provisioning\Provisioner;

/**
 * Runs everything that should happen once an invoice is fully paid.
 */
class InvoicePaidHandler
{
    public const OVERDUE_REASON = 'Overdue on payment';

    public function __construct(
        private Provisioner $provisioner,
        private TemplateMailer $mailer,
    ) {}

    public function handle(Invoice $invoice): void
    {
        $invoice->loadMissing('items.service.product', 'client');

        foreach ($invoice->items as $item) {
            if ($item->service !== null && $item->type === InvoiceItem::TYPE_SERVICE) {
                $this->handleServiceItem($item, $item->service);
            }
        }

        Order::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', OrderStatus::Pending)
            ->update(['status' => OrderStatus::Active]);

        if ($invoice->total > 0) {
            $this->mailer->send('invoice.payment_received', $invoice->client, TemplateMailer::invoiceContext($invoice));
        }
    }

    private function handleServiceItem(InvoiceItem $item, Service $service): void
    {
        $periodStart = $item->period_start;

        if ($periodStart !== null && $service->next_due_date !== null && $periodStart->isSameDay($service->next_due_date)) {
            $service->next_due_date = $service->billing_cycle->isRecurring()
                ? $service->billing_cycle->advance($periodStart)
                : null;
            $service->save();
        }

        if ($service->status === ServiceStatus::Pending && $service->product->auto_setup === AutoSetup::OnPayment) {
            ProvisionService::dispatch($service);

            return;
        }

        if ($service->status === ServiceStatus::Suspended
            && $service->suspension_reason === self::OVERDUE_REASON
            && ! $this->hasOtherOverdueInvoices($service, $item->invoice_id)) {
            $this->provisioner->unsuspend($service);
        }
    }

    private function hasOtherOverdueInvoices(Service $service, int $paidInvoiceId): bool
    {
        return Invoice::query()
            ->where('id', '!=', $paidInvoiceId)
            ->where('status', InvoiceStatus::Unpaid)
            ->whereDate('due_at', '<', today())
            ->whereHas('items', fn ($query) => $query->where('service_id', $service->id))
            ->exists();
    }
}
