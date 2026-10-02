<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\Service;
use App\Models\ServiceAddon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponsAndAddonsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_coupon_takes_money_off_the_first_invoice(): void
    {
        $product = Product::factory()->priced(1000, setupFee: 500)->create();
        Coupon::factory()->create(['code' => 'WELCOME20', 'value' => 20]);
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'razstudio.com']);
        $this->post(route('cart.coupon'), ['code' => 'welcome20'])->assertSessionHasNoErrors();

        $this->get(route('cart.show'))->assertSee('WELCOME20')->assertSee('-$2.00')->assertSee('$13.00');
        $this->post(route('checkout.store'))->assertRedirect();

        $order = Order::query()->sole();
        $invoice = $order->invoice;
        $service = $order->services()->sole();

        $this->assertSame(1300, $invoice->total, '$10 plan + $5 setup - 20% of the plan price.');
        $this->assertSame(200, $order->discount);
        $this->assertSame(-200, $invoice->items->firstWhere('type', InvoiceItem::TYPE_DISCOUNT)->amount);
        $this->assertSame(1000, $service->recurring_amount, 'Renewals are full price for a first-payment coupon.');
        $this->assertNull($service->coupon_id);
        $this->assertSame(1, Coupon::query()->sole()->uses);
        $this->assertSame(1, Coupon::query()->sole()->redemptions()->count());
    }

    public function test_a_coupon_for_the_first_three_payments_stops_after_three(): void
    {
        $product = Product::factory()->priced(1000)->create();
        Coupon::factory()->create(['code' => 'THREE', 'value' => 50, 'recurring' => Coupon::RECURRING_COUNT, 'recurring_count' => 3]);
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'razstudio.com']);
        $this->post(route('cart.coupon'), ['code' => 'THREE']);
        $this->post(route('checkout.store'));

        $service = Service::query()->sole();
        $this->assertSame(500, $service->order->invoice->total);
        $this->assertSame(2, $service->coupon_payments_left);

        $totals = [];

        foreach (range(1, 3) as $month) {
            $due = CarbonImmutable::today()->addMonthsNoOverflow($month);
            $service->update(['status' => ServiceStatus::Active, 'next_due_date' => $due]);
            app(RenewalGenerator::class)->generate($due);
            $totals[] = $client->invoices()->latest('id')->first()->total;
        }

        $this->assertSame([500, 500, 1000], $totals);
        $this->assertNull($service->fresh()->coupon_id);
    }

    public function test_a_renewal_a_coupon_makes_free_is_paid_at_once_and_renews_the_service(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'FREEFOREVER', 'value' => 100, 'recurring' => Coupon::RECURRING_EVERY]);
        $due = CarbonImmutable::today()->addDays(3);
        $service = Service::factory()->create(['recurring_amount' => 1000, 'next_due_date' => $due, 'coupon_id' => $coupon->id]);

        $this->assertSame(1, app(RenewalGenerator::class)->generate());

        $invoice = $service->client->invoices()->sole();
        $this->assertSame(0, $invoice->total);
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertTrue($service->fresh()->next_due_date->isSameDay($due->addMonthNoOverflow()));

        // Running again, or later, neither invoices the period again nor moves the date twice.
        app(RenewalGenerator::class)->generate();
        $this->travelTo($due->addDays(10));
        app(DailyAutomation::class)->run();

        $this->assertSame(1, $service->client->invoices()->count());
        $this->assertSame(1, $coupon->redemptions()->count());
        $this->assertTrue($service->fresh()->next_due_date->isSameDay($due->addMonthNoOverflow()));
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_a_free_renewal_left_unpaid_by_an_older_version_is_paid_by_the_next_run(): void
    {
        $due = CarbonImmutable::today();
        $service = Service::factory()->create(['recurring_amount' => 1000, 'next_due_date' => $due]);
        $invoice = app(InvoiceManager::class)->create($service->client, [
            ['type' => InvoiceItem::TYPE_SERVICE, 'service_id' => $service->id, 'description' => 'Hosting', 'amount' => 1000, 'period_start' => $due, 'period_end' => $due->addMonthNoOverflow()->subDay(), 'billing_key' => RenewalGenerator::billingKey('service', $service->id, $due)],
            ['type' => InvoiceItem::TYPE_DISCOUNT, 'service_id' => $service->id, 'description' => 'Coupon', 'amount' => -1000],
        ]);

        app(RenewalGenerator::class)->generate();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertTrue($service->fresh()->next_due_date->isSameDay($due->addMonthNoOverflow()));
    }

    public function test_a_paid_add_on_on_a_free_plan_is_renewed(): void
    {
        $due = CarbonImmutable::today()->addDays(3);
        $service = Service::factory()->create(['recurring_amount' => 0, 'next_due_date' => $due]);
        $service->addons()->create(['name' => 'Daily backups', 'recurring_amount' => 500, 'status' => ServiceAddon::STATUS_ACTIVE]);
        $service->addons()->create(['name' => 'Old extra', 'recurring_amount' => 900, 'status' => 'cancelled']);

        $this->assertSame(1, app(RenewalGenerator::class)->generate());
        $this->assertSame(0, app(RenewalGenerator::class)->generate());

        $invoice = $service->client->invoices()->sole();
        $this->assertSame(500, $invoice->total, 'Only the active add-on is billed.');

        app(PaymentRecorder::class)->record($invoice, 500, 'banktransfer', 'bank-addon');
        $this->assertTrue($service->fresh()->next_due_date->isSameDay($due->addMonthNoOverflow()));

        // A free plan with no paid add-on is not invoiced.
        $free = Service::factory()->create(['recurring_amount' => 0, 'next_due_date' => $due]);
        app(RenewalGenerator::class)->generate();
        $this->assertSame(0, $free->client->invoices()->count());
    }

    public function test_coupon_rules_are_checked(): void
    {
        $product = Product::factory()->priced(1000)->create();
        $other = Product::factory()->priced(1000)->create();
        Coupon::factory()->create(['code' => 'ENDED', 'ends_at' => today()->subDay()]);
        Coupon::factory()->create(['code' => 'NEWONLY', 'new_clients_only' => true]);
        Coupon::factory()->create(['code' => 'OTHERPLAN', 'product_ids' => [$other->id]]);

        $client = Client::factory()->create();
        Order::factory()->create(['client_id' => $client->id]);

        $this->actingAs($client, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'razstudio.com']);

        $this->post(route('cart.coupon'), ['code' => 'NOPE'])->assertSessionHasErrors(['code' => 'We do not know the coupon NOPE.']);
        $this->post(route('cart.coupon'), ['code' => 'ENDED'])->assertSessionHasErrors(['code' => 'This coupon has ended.']);
        $this->post(route('cart.coupon'), ['code' => 'NEWONLY'])->assertSessionHasErrors(['code' => 'This coupon is for new clients only.']);

        $this->post(route('cart.coupon'), ['code' => 'OTHERPLAN'])->assertSessionHasNoErrors();
        $this->get(route('cart.show'))->assertSee('OTHERPLAN')->assertDontSee('You save');
    }

    public function test_a_coupon_link_puts_the_coupon_in_the_cart(): void
    {
        Coupon::factory()->create(['code' => 'SPRING']);

        $this->get(route('store.index', ['coupon' => 'spring']))->assertOk()->assertSee('Coupon SPRING is applied');
        $this->assertSame('SPRING', session('cart.coupon'));
    }

    public function test_add_ons_are_ordered_and_renewed_with_the_service(): void
    {
        $product = Product::factory()->priced(1000)->create();
        $backups = ProductAddon::factory()->priced(200)->create(['name' => 'Daily backups']);
        ProductAddon::factory()->priced(300, BillingCycle::Annually)->create(['name' => 'Yearly only']);
        $client = Client::factory()->create();

        $this->get(route('store.product', [$product->group, $product]))->assertSee('Daily backups');

        $this->actingAs($client, 'web')->post(route('cart.store'), [
            'product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'razstudio.com', 'addons' => [$backups->id],
        ]);
        $this->get(route('cart.show'))->assertSee('Daily backups')->assertSee('$12.00');
        $this->post(route('checkout.store'));

        $service = Service::query()->sole();
        $this->assertSame(1200, $service->order->invoice->total);
        $this->assertSame('Daily backups', $service->addons()->sole()->name);
        $this->assertSame(200, $service->addons()->sole()->recurring_amount);

        $due = CarbonImmutable::today()->addMonthNoOverflow();
        $service->update(['status' => ServiceStatus::Active, 'next_due_date' => $due]);
        app(RenewalGenerator::class)->generate($due);

        $renewal = $client->invoices()->latest('id')->first();
        $this->assertSame(1200, $renewal->total);
        $this->assertSame(InvoiceItem::TYPE_ADDON, $renewal->items->last()->type);
    }

    public function test_staff_create_coupons_and_add_ons(): void
    {
        $this->signInAdmin();
        $product = Product::factory()->priced(1000)->create();

        $this->post(route('admin.coupons.store'), [
            'code' => 'vps10', 'type' => 'fixed', 'value' => '10', 'recurring' => 'every',
            'product_ids' => [$product->id], 'is_active' => '1', 'max_uses' => '50',
        ])->assertRedirect(route('admin.coupons.index'));

        $coupon = Coupon::query()->sole();
        $this->assertSame('VPS10', $coupon->code);
        $this->assertSame(1000, $coupon->value);
        $this->assertSame('USD', $coupon->currency);
        $this->assertSame([$product->id], $coupon->product_ids);
        $this->get(route('admin.coupons.index'))->assertOk()->assertSee('VPS10')->assertSee('$10.00 off');
        $this->get(route('admin.coupons.edit', $coupon))->assertOk();

        $this->post(route('admin.product-addons.store'), [
            'name' => 'Priority support', 'is_visible' => '1',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '5', 'setup_fee' => '0']],
        ])->assertRedirect(route('admin.product-addons.index'));

        $addon = ProductAddon::query()->sole();
        $this->assertSame(500, $addon->priceFor('USD', BillingCycle::Monthly)->price);
        $this->get(route('admin.product-addons.index'))->assertOk()->assertSee('Priority support');
    }
}
