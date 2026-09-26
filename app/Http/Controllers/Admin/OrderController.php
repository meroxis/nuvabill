<?php

namespace App\Http\Controllers\Admin;

use App\Billing\InvoiceManager;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

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
        $order->load('client', 'invoice', 'services.product', 'services.server');

        return view('admin.orders.show', ['order' => $order]);
    }

    /**
     * Set up every pending service now, even if the invoice is not paid yet.
     */
    public function accept(Order $order, Provisioner $provisioner): RedirectResponse
    {
        $failures = [];

        foreach ($order->services()->with('product', 'client', 'server')->where('status', ServiceStatus::Pending)->get() as $service) {
            $result = $provisioner->create($service);

            if (! $result->success) {
                $failures[] = $service->label().': '.$result->message;
            }
        }

        if ($failures === []) {
            $order->update(['status' => OrderStatus::Active]);
            Activity::log('order.accepted', "Order #{$order->number} accepted", $order);

            return back()->with('status', __('Order accepted and services set up.'));
        }

        return back()->with('error', __('Some services could not be set up: :errors', ['errors' => implode('; ', $failures)]));
    }

    public function cancel(Order $order, InvoiceManager $invoices): RedirectResponse
    {
        $order->services()
            ->where('status', ServiceStatus::Pending)
            ->update(['status' => ServiceStatus::Cancelled, 'cancelled_at' => now(), 'next_due_date' => null]);

        if ($order->invoice !== null) {
            $invoices->cancel($order->invoice);
        }

        $order->update(['status' => OrderStatus::Cancelled]);
        Activity::log('order.cancelled', "Order #{$order->number} cancelled", $order);

        return back()->with('status', __('Order cancelled.'));
    }
}
