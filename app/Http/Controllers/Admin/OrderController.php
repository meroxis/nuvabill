<?php

namespace App\Http\Controllers\Admin;

use App\Billing\InvoiceManager;
use App\Domains\DomainProvisioner;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $status = OrderStatus::tryFrom((string) $request->query('status'));

        return view('admin.orders.index', [
            'orders' => Order::query()
                ->with('client', 'invoice')
                ->withCount('services')
                ->when($status, fn ($query) => $query->where('status', $status))
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'status' => $status,
        ]);
    }

    public function show(Order $order): View
    {
        $order->load('client', 'invoice', 'services.product', 'services.server', 'domains');

        return view('admin.orders.show', ['order' => $order]);
    }

    /**
     * Set up every pending service and register every pending domain now, even if the invoice is not
     * paid yet. Also clears a fraud review, so later payments set things up automatically again.
     * Only a pending order can be accepted, so a page left open or a second click changes nothing.
     */
    public function accept(Order $order, Provisioner $provisioner, DomainProvisioner $domains): RedirectResponse
    {
        return $this->whilePending($order, function () use ($order, $provisioner, $domains): RedirectResponse {
            $failures = [];
            $order->update(['needs_review' => false]);

            foreach ($order->services()->with('product', 'client', 'server')->where('status', ServiceStatus::Pending)->get() as $service) {
                // A queued setup may have handled it in the meantime.
                if ($service->refresh()->status !== ServiceStatus::Pending) {
                    continue;
                }

                $result = $provisioner->create($service);

                if (! $result->success) {
                    $failures[] = $service->label().': '.$result->message;
                }
            }

            foreach ($order->domains()->with('client')->where('status', DomainStatus::Pending)->whereNotNull('registrar')->get() as $domain) {
                $result = $domains->register($domain);

                if (! $result->success) {
                    $failures[] = $domain->name.': '.$result->message;
                }
            }

            if ($failures === []) {
                $order->update(['status' => OrderStatus::Active]);
                Activity::log('order.accepted', "Order #{$order->number} accepted", $order);

                return back()->with('status', __('Order accepted and services set up.'));
            }

            return back()->with('error', __('Some services could not be set up: :errors', ['errors' => implode('; ', $failures)]));
        });
    }

    /**
     * Cancel a pending order: its unpaid invoice first, then what still waits to be set up. Money
     * already paid on the invoice goes back to the client's wallet; when it cannot, nothing changes.
     */
    public function cancel(Order $order, InvoiceManager $invoices): RedirectResponse
    {
        return $this->whilePending($order, function () use ($order, $invoices): RedirectResponse {
            $invoice = $order->invoice;
            $paid = $invoice?->status === InvoiceStatus::Unpaid ? $invoice->amount_paid : 0;

            try {
                DB::transaction(function () use ($order, $invoice, $invoices): void {
                    if ($invoice !== null) {
                        $invoices->cancel($invoice);
                    }

                    $order->domains()->where('status', DomainStatus::Pending)->update(['status' => DomainStatus::Cancelled]);

                    $order->services()
                        ->where('status', ServiceStatus::Pending)
                        ->update(['status' => ServiceStatus::Cancelled, 'cancelled_at' => now(), 'next_due_date' => null]);

                    $order->update(['status' => OrderStatus::Cancelled]);
                });
            } catch (RuntimeException $exception) {
                return back()->with('error', $exception->getMessage());
            }

            Activity::log('order.cancelled', "Order #{$order->number} cancelled", $order);

            return back()->with('status', match (true) {
                $invoice?->status === InvoiceStatus::Paid => __('Order cancelled. Its invoice is paid, so give the money back with a refund or a credit note if needed.'),
                $paid > 0 && $invoice?->status === InvoiceStatus::Cancelled => __('Order cancelled. The :amount paid on its invoice went back to the client\'s wallet.', ['amount' => money($paid, $invoice->currency)]),
                default => __('Order cancelled.'),
            });
        });
    }

    /**
     * Accept or cancel only while the order is still pending, and one change per order at a time.
     * A cache lock is used, not a row lock, because setting up services calls other servers.
     *
     * @param  callable(): RedirectResponse  $action
     */
    private function whilePending(Order $order, callable $action): RedirectResponse
    {
        $lock = Cache::lock("order-{$order->id}", 300);

        if (! $lock->get()) {
            return back()->with('error', __('Another change to this order is running. Try again in a minute.'));
        }

        try {
            if ($order->refresh()->status !== OrderStatus::Pending) {
                return back()->with('error', __('Only pending orders can be accepted or cancelled.'));
            }

            return $action();
        } finally {
            $lock->release();
        }
    }
}
