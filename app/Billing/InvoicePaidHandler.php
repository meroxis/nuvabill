<?php

namespace App\Billing;

use App\Enums\AutoSetup;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Jobs\ProvisionService;
use App\Jobs\RegisterDomain;
use App\Jobs\RenewDomain;
use App\Mail\TemplateMailer;
use App\Models\Domain;
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
        private Wallet $wallet,
    ) {}

    public function handle(Invoice $invoice): void
    {
        $invoice->loadMissing('items.service.product', 'items.service.order', 'items.domain.order', 'client');

        foreach ($invoice->items as $item) {
            if ($item->service !== null && $item->type === InvoiceItem::TYPE_SERVICE) {
                $this->handleServiceItem($item, $item->service);
            }

            if ($item->domain !== null && in_array($item->type, InvoiceItem::DOMAIN_TYPES, true)) {
                $this->handleDomainItem($item, $item->domain);
            }

            if ($item->type === InvoiceItem::TYPE_CREDIT && $item->amount > 0) {
                $this->wallet->change($invoice->client, $item->amount, __('Added funds with invoice :number', ['number' => $invoice->displayNumber()]), $invoice);
            }
        }

        if ($invoice->items->contains('type', InvoiceItem::TYPE_PLAN_CHANGE)) {
            app(PlanChanges::class)->applyForInvoice($invoice);
        }

        Order::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', OrderStatus::Pending)
            ->where('needs_review', false)
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
                ? $service->billing_cycle->advance($periodStart, $service->registration_date?->day)
                : null;
            $service->save();
        }

        $setup = $service->product->auto_setup;

        if ($service->status === ServiceStatus::Pending
            && ($setup === AutoSetup::OnPayment || ($setup === AutoSetup::OnOrder && $this->setupWaitsForThisPayment($item, $service)))) {
            if ($service->order?->needs_review !== true) {
                ProvisionService::dispatch($service);
            }

            return;
        }

        if ($service->status === ServiceStatus::Suspended
            && $service->suspension_reason === self::OVERDUE_REASON
            && ! $this->hasOtherOverdueInvoices($service, $item->invoice_id)) {
            $this->provisioner->unsuspend($service);
        }
    }

    /**
     * A service set up "as soon as the order is placed" is set up again when its order invoice is
     * paid only when its module refused that setup because it needed this payment (a VPS with paid
     * extras, say). After any other failure it waits for staff: a create without a clear answer
     * may have made an account, and a second create would give the client a second one.
     */
    private function setupWaitsForThisPayment(InvoiceItem $item, Service $service): bool
    {
        return $service->order !== null
            && (int) $service->order->invoice_id === (int) $item->invoice_id
            && $this->provisioner->waitsForPayment($service);
    }

    private function handleDomainItem(InvoiceItem $item, Domain $domain): void
    {
        if ($item->type === InvoiceItem::TYPE_DOMAIN_RENEW) {
            if ($domain->status->isRenewable() && $item->period_start !== null && $domain->next_due_date?->isSameDay($item->period_start)) {
                RenewDomain::dispatch($domain, max(1, (int) round($item->period_start->diffInYears($item->period_end->addDay()))));
            }

            return;
        }

        if ($domain->status === DomainStatus::Pending && setting('domains.auto_register') && filled($domain->registrar) && $domain->order?->needs_review !== true) {
            RegisterDomain::dispatch($domain);
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
