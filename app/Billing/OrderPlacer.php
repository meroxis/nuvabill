<?php

namespace App\Billing;

use App\Domains\DomainProvisioner;
use App\Enums\AutoSetup;
use App\Enums\DomainStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Jobs\ProvisionService;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Service;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Turns a checked-out cart into an order, pending services and domains, and the first invoice.
 */
class OrderPlacer
{
    public function __construct(
        private InvoiceManager $invoices,
        private PaymentRecorder $payments,
        private TemplateMailer $mailer,
        private FraudChecker $fraud,
    ) {}

    /**
     * @param  Collection<int, CartLine>  $lines
     * @param  string|null  $ipCountry  The visitor's country from a trusted proxy, for the fraud check.
     */
    public function place(Client $client, Collection $lines, ?string $ipAddress = null, ?string $ipCountry = null): Order
    {
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot place an empty order.');
        }

        $fraudReasons = $this->fraud->reasons($client, $ipAddress, $ipCountry);

        $order = DB::transaction(function () use ($client, $lines, $ipAddress, $fraudReasons): Order {
            $today = CarbonImmutable::today();

            $order = Order::create([
                'number' => $this->uniqueOrderNumber(),
                'client_id' => $client->id,
                'status' => OrderStatus::Pending,
                'needs_review' => $fraudReasons !== [],
                'fraud_reasons' => $fraudReasons ?: null,
                'currency' => $client->currency,
                'ip_address' => $ipAddress,
            ]);

            $items = [];
            $nameservers = $this->nameserversFor($lines);

            foreach ($lines as $line) {
                if ($line->isDomain()) {
                    $items[] = $this->domainItem($client, $order, $line, $nameservers, $today);

                    continue;
                }

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

        $order->load('invoice', 'services.product', 'domains');

        Activity::log('order.placed', "Order #{$order->number} placed", $order, $client);

        if ($order->needs_review) {
            Activity::log('order.review', "Order #{$order->number} needs a review: ".implode(' ', $fraudReasons), $order, $client);
        } else {
            foreach ($order->services as $service) {
                if ($service->product->auto_setup === AutoSetup::OnOrder) {
                    ProvisionService::dispatch($service);
                }
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

    /**
     * @param  list<string>  $nameservers
     * @return array<string, mixed>
     */
    private function domainItem(Client $client, Order $order, CartLine $line, array $nameservers, CarbonImmutable $today): array
    {
        $domain = Domain::create([
            'client_id' => $client->id,
            'order_id' => $order->id,
            'name' => $line->domain,
            'tld' => $line->tldPrice->tld,
            'registrar' => $line->tldPrice->registrar,
            'order_type' => $line->domainAction,
            'status' => DomainStatus::Pending,
            'years' => $line->years,
            'currency' => $client->currency,
            'first_payment_amount' => $line->price,
            'recurring_amount' => $line->tldPrice->priceFor('renew', $line->years),
            'nameservers' => $nameservers,
            'epp_code' => $line->eppCode,
        ]);

        $type = $line->domainAction === Domain::TYPE_TRANSFER ? InvoiceItem::TYPE_DOMAIN_TRANSFER : InvoiceItem::TYPE_DOMAIN_REGISTER;

        return LineItems::domainPeriod($domain, $type, $line->years, $line->price, $today);
    }

    /**
     * Point new domains at the hosting server ordered with them, or at the default nameservers.
     *
     * @param  Collection<int, CartLine>  $lines
     * @return list<string>
     */
    private function nameserversFor(Collection $lines): array
    {
        $fromServer = $lines->first(fn (CartLine $line): bool => ! $line->isDomain() && $line->product?->server?->nameservers)?->product->server->nameservers;

        return array_values(array_filter((array) ($fromServer ?: DomainProvisioner::defaultNameservers())));
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
