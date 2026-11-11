<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A coupon use is held by an order while its invoice can still be paid. When the invoice is
 * cancelled unpaid, the use is free again, once.
 */
class CouponUseReturnTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->product = Product::factory()->priced(1000)->withoutDomain()->create();
    }

    public function test_an_unpaid_order_the_nightly_run_cancels_gives_its_coupon_use_back(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LIMITED', 'value' => 20, 'max_uses' => 1]);
        $order = $this->checkout(Client::factory()->create(), 'LIMITED');

        $this->assertSame(1, $coupon->fresh()->uses);
        $this->assertSame(200, $order->discount);

        // While the order waits for payment, the use is taken.
        $other = Client::factory()->create();
        $this->actingAs($other, 'web')->post(route('cart.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly']);
        $this->post(route('cart.coupon'), ['code' => 'LIMITED'])->assertSessionHasErrors(['code' => 'This coupon has been used up.']);

        $summary = app(DailyAutomation::class)->run(today()->addDays(7));

        $this->assertSame(1, $summary['orders_cancelled']);
        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, $coupon->fresh()->uses);
        $this->assertSame(0, $coupon->redemptions()->count());

        // The product is still in the cart of the other client.
        $this->post(route('cart.coupon'), ['code' => 'LIMITED'])->assertSessionHasNoErrors();
        $this->post(route('checkout.store'))->assertRedirect();
        $second = Order::query()->latest('id')->firstOrFail();

        $this->assertSame($other->id, $second->client_id);
        $this->assertSame(200, $second->discount, 'The next client gets the coupon.');
        $this->assertSame(1, $coupon->fresh()->uses);
    }

    public function test_a_use_is_given_back_once_when_staff_cancel_the_invoice_and_the_order_is_cancelled_later(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ONCE', 'value' => 20, 'max_uses_per_client' => 1]);
        $client = Client::factory()->create();
        // Another order of another client keeps its use.
        $kept = $this->checkout(Client::factory()->create(), 'ONCE');
        $order = $this->checkout($client, 'ONCE');
        $this->assertSame(2, $coupon->fresh()->uses);

        $this->signInAdmin();
        $this->post(route('admin.invoices.cancel', $order->invoice))->assertSessionHas('status', 'Invoice cancelled.');

        $this->assertSame(1, $coupon->fresh()->uses);
        $this->assertSame([$kept->id], $coupon->redemptions()->pluck('order_id')->all());

        app(DailyAutomation::class)->run(today()->addDays(7));

        $this->assertSame(OrderStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(OrderStatus::Cancelled, $kept->fresh()->status);
        $this->assertSame(0, $coupon->fresh()->uses, 'Each order gave its use back once.');

        // The limit per client counts the cancelled order no more.
        $this->assertSame(200, $this->checkout($client, 'ONCE')->discount);
    }

    public function test_staff_cancelling_an_unpaid_order_gives_the_use_back(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LIMITED', 'value' => 20, 'max_uses' => 5]);
        $order = $this->checkout(Client::factory()->create(), 'LIMITED');

        $this->signInAdmin();
        $this->post(route('admin.orders.cancel', $order))->assertSessionHas('status', 'Order cancelled.');

        $this->assertSame(InvoiceStatus::Cancelled, $order->invoice->fresh()->status);
        $this->assertSame(0, $coupon->fresh()->uses);
        $this->assertSame(0, $coupon->redemptions()->count());
    }

    public function test_a_paid_order_keeps_its_use_when_it_is_cancelled(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LIMITED', 'value' => 20, 'max_uses' => 5]);
        $order = $this->checkout(Client::factory()->create(), 'LIMITED');
        app(PaymentRecorder::class)->record($order->invoice, $order->invoice->total, 'banktransfer');

        app(InvoiceManager::class)->cancel($order->invoice->fresh());
        app(DailyAutomation::class)->run(today()->addDays(30));

        $this->assertSame(InvoiceStatus::Paid, $order->invoice->fresh()->status);
        $this->assertSame(1, $coupon->fresh()->uses);
        $this->assertSame(1, $coupon->redemptions()->count());
    }

    public function test_the_count_of_uses_never_goes_below_zero(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'LIMITED', 'value' => 20]);
        $order = $this->checkout(Client::factory()->create(), 'LIMITED');
        Coupon::query()->whereKey($coupon->id)->update(['uses' => 0]);

        app(InvoiceManager::class)->cancel($order->invoice);

        $this->assertSame(0, $coupon->fresh()->uses);
        $this->assertSame(0, $coupon->redemptions()->count());
    }

    public function test_a_cancelled_renewal_no_longer_counts_for_the_client_and_leaves_the_order_count_alone(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'EVERY', 'value' => 50, 'recurring' => Coupon::RECURRING_EVERY]);
        Coupon::query()->whereKey($coupon->id)->update(['uses' => 1]);
        $due = CarbonImmutable::today()->addDays(3);
        $service = Service::factory()->create(['recurring_amount' => 1000, 'next_due_date' => $due, 'coupon_id' => $coupon->id]);

        app(RenewalGenerator::class)->generate();
        $renewal = $service->client->invoices()->sole();
        $this->assertSame(1, $coupon->redemptions()->count());

        app(InvoiceManager::class)->cancel($renewal);

        $this->assertSame(0, $coupon->redemptions()->count());
        $this->assertSame(1, $coupon->fresh()->uses, 'Only orders count towards the uses of a coupon.');

        // The period is invoiced again, with the discount.
        app(RenewalGenerator::class)->generate();
        $this->assertSame(500, $service->client->invoices()->latest('id')->first()->total);
        $this->assertSame(1, $coupon->redemptions()->count());
    }

    public function test_checkout_is_limited_for_each_client(): void
    {
        $client = Client::factory()->create();
        $this->actingAs($client, 'web');

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('cart.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly']);
            $this->post(route('checkout.store'))->assertRedirect();
        }

        $this->post(route('cart.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly']);
        $this->post(route('checkout.store'))->assertStatus(429);
        $this->assertSame(10, Order::query()->count());

        // Another client is not held back by it.
        $this->checkout(Client::factory()->create());
        $this->assertSame(11, Order::query()->count());
    }

    private function checkout(Client $client, ?string $coupon = null): Order
    {
        $this->actingAs($client, 'web');
        $this->post(route('cart.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly']);

        if ($coupon !== null) {
            $this->post(route('cart.coupon'), ['code' => $coupon])->assertSessionHasNoErrors();
        }

        $this->post(route('checkout.store'))->assertRedirect();

        return Order::query()->latest('id')->firstOrFail();
    }
}
