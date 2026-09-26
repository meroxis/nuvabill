<?php

namespace App\Billing;

use App\Enums\AutoSetup;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Jobs\ProvisionService;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Order;
use App\Models\Service;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a checked-out cart into an order, pending services and the first invoice.
 */
class OrderPlacer
{
    public function __construct(
        private InvoiceManager $invoices,
        private PaymentRecorder $payments,
        private TemplateMailer $mailer,
    ) {}

    /**
     * @param  Collection<int, CartLine>  $lines
     */
    public function place(Client $client, Collection $lines, ?string $ipAddress = null): Order
    {
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot place an empty order.');
        }

        $order = DB::transaction(function () use ($client, $lines, $ipAddress): Order {
            $today = CarbonImmutable::today();

            $order = Order::create([
                'number' => $this->uniqueOrderNumber(),
                'client_id' => $client->id,
                'status' => OrderStatus::Pending,
                'currency' => $client->currency,
                'ip_address' => $ipAddress,
            ]);

            $items = [];

            foreach ($lines as $line) {
                $service = Service::create([
                    'client_id' => $client->id,
                    'order_id' => $order->id,
                    'product_id' => $line->product->id,
                    'server_id' => $line->product->server_id,
                    'domain' => $line->domain,
                    'status' => ServiceStatus::Pending,
                    'billing_cycle' => $line->cycle,
                    'currency' => $client->currency,
                    'first_payment_amount' => $line->dueToday(),
                    'recurring_amount' => $line->cycle->isRecurring() ? $line->price : 0,
                    'registration_date' => $today,
                    'next_due_date' => $today,
                ]);

                $service->setRelation('product', $line->product);
                $items[] = LineItems::servicePeriod($service, $today, $line->price);

                if ($line->setupFee > 0) {
                    $items[] = LineItems::setupFee($service, $line->setupFee);
                }
            }

            $invoice = $this->invoices->create($client, $items, dueAt: $today);
            $order->update(['invoice_id' => $invoice->id, 'total' => $invoice->total]);

            return $order;
        });

        $order->load('invoice', 'services.product');

        Activity::log('order.placed', "Order #{$order->number} placed", $order, $client);

        foreach ($order->services as $service) {
            if ($service->product->auto_setup === AutoSetup::OnOrder) {
                ProvisionService::dispatch($service);
            }
        }

        if ($order->invoice->total === 0) {
            $this->payments->settleFreeInvoice($order->invoice);
        }

        $this->mailer->send('order.confirmation', $client, TemplateMailer::invoiceContext($order->invoice) + [
            'order' => ['number' => $order->number, 'total' => money($order->total, $order->currency)],
        ]);

        $this->mailer->sendToStaff('admin.new_order', [
            'client' => TemplateMailer::clientContext($client),
            'order' => ['number' => $order->number, 'total' => money($order->total, $order->currency)],
            'admin_url' => route('admin.orders.show', $order),
        ]);

        return $order->refresh();
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
