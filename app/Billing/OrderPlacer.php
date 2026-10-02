<?php

namespace App\Billing;

use App\Domains\DomainProvisioner;
use App\Enums\AutoSetup;
use App\Enums\DomainStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Events\OrderPlaced;
use App\Jobs\ProvisionService;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceAddon;
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
        private Wallet $wallet,
    ) {}

    /**
     * @param  Collection<int, CartLine>  $lines  Lines from the cart. With a coupon, they already carry their discounts.
     * @param  string|null  $ipCountry  The visitor's country from a trusted proxy, for the fraud check.
     *
     * @throws SoldOut When the order wants more of a product than is left; nothing is made then.
     */
    public function place(Client $client, Collection $lines, ?string $ipAddress = null, ?string $ipCountry = null, ?Coupon $coupon = null): Order
    {
        if ($lines->isEmpty()) {
            throw new InvalidArgumentException('Cannot place an empty order.');
        }

        if ($coupon === null && $lines->contains(fn (CartLine $line): bool => $line->discount > 0)) {
            throw new InvalidArgumentException('Discounted cart lines need their coupon.');
        }

        $fraudReasons = $this->fraud->reasons($client, $ipAddress, $ipCountry);

        $order = DB::transaction(function () use ($client, $lines, $ipAddress, $fraudReasons, $coupon): Order {
            $this->reserveStock($lines);
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
                    $items = [...$items, ...$this->domainItems($client, $order, $line, $nameservers, $today, $coupon)];

                    continue;
                }

                $keepsDiscount = $coupon !== null && $line->discount > 0 && $coupon->isRecurring();

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
                    'coupon_id' => $keepsDiscount ? $coupon->id : null,
                    'coupon_payments_left' => $keepsDiscount && $coupon->recurring === Coupon::RECURRING_COUNT ? $coupon->recurring_count - 1 : null,
                ]);

                $service->setRelation('product', $line->product);
                $items[] = LineItems::servicePeriod($service, $today, $line->price);

                if ($line->setupFee > 0) {
                    $items[] = LineItems::setupFee($service, $line->setupFee);
                }

                foreach ($line->addons as $addon) {
                    $service->addons()->create([
                        'product_addon_id' => $addon['id'],
                        'name' => $addon['name'],
                        'recurring_amount' => $line->cycle->isRecurring() ? $addon['price'] : 0,
                        'status' => ServiceAddon::STATUS_ACTIVE,
                    ]);

                    $items[] = LineItems::addonPeriod($service, $addon['name'], $today, $addon['price']);

                    if ($addon['setup_fee'] > 0) {
                        $items[] = ['type' => InvoiceItem::TYPE_ADDON, 'description' => __('Setup fee').' - '.$addon['name'], 'amount' => $addon['setup_fee'], 'service_id' => $service->id];
                    }
                }

                if ($coupon !== null && $line->discount > 0) {
                    $items[] = LineItems::discount($coupon, $line->discount, $service);
                }
            }

            $invoice = $this->invoices->create($client, $items, dueAt: $today);
            $discount = (int) $lines->sum(fn (CartLine $line): int => $line->discount);
            $order->update(['invoice_id' => $invoice->id, 'total' => $invoice->total, 'coupon_id' => $discount > 0 ? $coupon?->id : null, 'discount' => $discount]);

            if ($coupon !== null && $discount > 0) {
                $coupon->redemptions()->create(['client_id' => $client->id, 'order_id' => $order->id, 'invoice_id' => $invoice->id, 'amount' => $discount, 'currency' => $client->currency]);
                $coupon->increment('uses');
            }

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
        } elseif ($this->wallet->applyAutomatically($order->invoice) > 0) {
            $order->invoice->refresh();
        }

        $this->mailer->send('order.confirmation', $client, TemplateMailer::invoiceContext($order->invoice) + [
            'order' => ['number' => $order->number, 'total' => money($order->total, $order->currency)],
        ]);

        $this->mailer->sendToStaff('admin.new_order', [
            'client' => TemplateMailer::clientContext($client),
            'order' => ['number' => $order->number, 'total' => money($order->total, $order->currency)],
            'admin_url' => route('admin.orders.show', $order),
        ]);

        $order->refresh();

        OrderPlaced::dispatch($order);

        return $order;
    }

    /**
     * @param  list<string>  $nameservers
     * @return list<array<string, mixed>>
     */
    private function domainItems(Client $client, Order $order, CartLine $line, array $nameservers, CarbonImmutable $today, ?Coupon $coupon): array
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
            'first_payment_amount' => $line->dueToday(),
            'recurring_amount' => $line->tldPrice->priceFor('renew', $line->years),
            'nameservers' => $nameservers,
            'epp_code' => $line->eppCode,
        ]);

        $type = $line->domainAction === Domain::TYPE_TRANSFER ? InvoiceItem::TYPE_DOMAIN_TRANSFER : InvoiceItem::TYPE_DOMAIN_REGISTER;

        return array_values(array_filter([
            LineItems::domainPeriod($domain, $type, $line->years, $line->price, $today),
            $coupon !== null && $line->discount > 0 ? LineItems::discount($coupon, $line->discount, domain: $domain) : null,
        ]));
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

    /**
     * Products with a stock limit are locked until the order is made, so two checkouts at once
     * cannot both take the last one. Run inside the order's database transaction.
     *
     * @param  Collection<int, CartLine>  $lines
     *
     * @throws SoldOut
     */
    private function reserveStock(Collection $lines): void
    {
        $wanted = $lines->reject(fn (CartLine $line): bool => $line->isDomain())->countBy(fn (CartLine $line): int => $line->product->id);

        if ($wanted->isEmpty()) {
            return;
        }

        $limited = Product::query()->whereKey($wanted->keys()->all())->whereNotNull('stock')->orderBy('id')->lockForUpdate()->get();

        foreach ($limited as $product) {
            $left = (int) $product->stockLeft();

            if ($wanted[$product->id] > $left) {
                throw new SoldOut($product, $left);
            }
        }
    }

    private function uniqueOrderNumber(): string
    {
        do {
            $number = (string) random_int(1000000, 9999999);
        } while (Order::query()->where('number', $number)->exists());

        return $number;
    }
}
