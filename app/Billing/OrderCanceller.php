<?php

namespace App\Billing;

use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Cancels new orders nobody paid for (Settings → General → Automation), so the stock and server
 * room they hold go back on sale. Their services and domains that were not set up yet are
 * cancelled, and the unpaid invoice with them, so it can no longer be paid.
 */
class OrderCanceller
{
    public function __construct(private InvoiceManager $invoices) {}

    /**
     * Cancel the new orders whose invoice is still unpaid the set number of days after it was due,
     * or whose invoice staff cancelled. An order with anything set up already (a service made on
     * order, a domain registered), or an invoice with a payment on it or a card or gateway payment
     * still going through, is kept. Returns how many orders were cancelled.
     */
    public function cancelUnpaid(?CarbonInterface $today = null): int
    {
        $days = (int) setting('orders.cancel_unpaid_days');

        if ($days <= 0) {
            return 0;
        }

        $cutoff = CarbonImmutable::instance($today ?? today())->subDays($days);
        $cancelled = 0;

        Order::query()
            ->where('status', OrderStatus::Pending)
            ->whereHas('invoice', fn (Builder $query) => $query
                ->whereIn('status', [InvoiceStatus::Unpaid, InvoiceStatus::Cancelled])
                ->where('amount_paid', 0)
                ->whereDate('due_at', '<=', $cutoff))
            ->tap(fn (Builder $query) => $this->nothingSetUp($query))
            ->lazyById()
            ->each(function (Order $order) use ($days, &$cancelled): void {
                if ($this->cancelIfUnpaid($order, $days)) {
                    $cancelled++;
                }
            });

        return $cancelled;
    }

    /**
     * Cancel the order's services and domains that were not set up yet, its invoice when unpaid,
     * and the order itself.
     */
    public function cancel(Order $order, string $note = ''): void
    {
        DB::transaction(function () use ($order, $note): void {
            $order->domains()->where('status', DomainStatus::Pending)->update(['status' => DomainStatus::Cancelled]);
            $order->services()
                ->where('status', ServiceStatus::Pending)
                ->update(['status' => ServiceStatus::Cancelled, 'cancelled_at' => now(), 'next_due_date' => null]);

            if ($order->invoice !== null) {
                $this->invoices->cancel($order->invoice);
            }

            $order->update(['status' => OrderStatus::Cancelled]);
            Activity::log('order.cancelled', "Order #{$order->number} cancelled".($note !== '' ? ": {$note}" : ''), $order);
        });
    }

    /**
     * The invoice is locked the way a payment locks it, so a payment arriving now either lands
     * first and keeps the order, or finds the invoice cancelled and is not applied.
     */
    private function cancelIfUnpaid(Order $order, int $days): bool
    {
        return DB::transaction(function () use ($order, $days): bool {
            $invoice = Invoice::query()->lockForUpdate()->find($order->invoice_id);
            $order = Order::query()->whereKey($order->id)->tap(fn (Builder $query) => $this->nothingSetUp($query))->first();

            if ($order === null || $invoice === null || $order->status !== OrderStatus::Pending
                || ! in_array($invoice->status, [InvoiceStatus::Unpaid, InvoiceStatus::Cancelled], true)
                || $invoice->amount_paid > 0 || PaymentUnderway::on($invoice)) {
                return false;
            }

            $this->cancel($order->setRelation('invoice', $invoice), "its invoice was not paid within {$days} days");

            return true;
        });
    }

    /**
     * Only orders whose services and domains are all still waiting (or already stopped): a service
     * made on order, or a domain registered or transferred, stays with its unpaid invoice.
     *
     * @param  Builder<Order>  $query
     */
    private function nothingSetUp(Builder $query): void
    {
        $query
            ->whereDoesntHave('services', fn (Builder $query) => $query->whereNotIn('status', [ServiceStatus::Pending, ServiceStatus::Cancelled, ServiceStatus::Terminated, ServiceStatus::Fraud]))
            ->whereDoesntHave('domains', fn (Builder $query) => $query->whereNotIn('status', [DomainStatus::Pending, DomainStatus::Cancelled, DomainStatus::Fraud]));
    }
}
