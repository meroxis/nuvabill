<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\CartLine;
use App\Billing\CouponUnavailable;
use App\Billing\OrderPlacer;
use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\CouponRedemption;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A coupon's limits are checked again while the order is made, so checkouts at the same moment
 * cannot use it more often than allowed.
 */
class CouponLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_coupon_used_up_by_another_checkout_is_refused(): void
    {
        $this->assertRefusedAfter(['max_uses' => 1], function (Coupon $coupon, Client $client): void {
            Coupon::query()->whereKey($coupon->id)->increment('uses');
            CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'client_id' => Client::factory()->create()->id, 'amount' => 1000, 'currency' => 'USD']);
        }, 'This coupon has been used up.');
    }

    public function test_a_once_per_client_coupon_cannot_be_redeemed_twice_at_once(): void
    {
        $this->assertRefusedAfter(['max_uses_per_client' => 1], function (Coupon $coupon, Client $client): void {
            Coupon::query()->whereKey($coupon->id)->increment('uses');
            CouponRedemption::query()->create(['coupon_id' => $coupon->id, 'client_id' => $client->id, 'amount' => 1000, 'currency' => 'USD']);
        }, 'You have already used this coupon.');
    }

    public function test_a_new_clients_only_coupon_is_checked_again_when_the_order_is_made(): void
    {
        $this->assertRefusedAfter(['new_clients_only' => true], function (Coupon $coupon, Client $client): void {
            Order::factory()->create(['client_id' => $client->id]);
        }, 'This coupon is for new clients only.');
    }

    public function test_a_coupon_with_uses_left_is_counted_once_per_order(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'TWICE', 'value' => 100, 'max_uses' => 2]);
        [$client, $lines, $checked] = $this->cartWith($coupon);

        app(OrderPlacer::class)->place($client, $lines, coupon: $checked);

        $this->assertSame(1, $coupon->fresh()->uses);
        $this->assertSame(1, $coupon->redemptions()->count());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_checkout_shows_the_coupon_problem_and_takes_the_coupon_out_of_the_cart(): void
    {
        $product = Product::factory()->priced(1000)->withoutDomain()->create();
        Coupon::factory()->create(['code' => 'ONCE', 'value' => 100, 'max_uses' => 1]);
        $this->mock(OrderPlacer::class, fn (MockInterface $mock) => $mock->shouldReceive('place')->andThrow(CouponUnavailable::because('This coupon has been used up.')));

        $this->actingAs(Client::factory()->create(), 'web');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly']);
        $this->post(route('cart.coupon'), ['code' => 'ONCE'])->assertSessionHasNoErrors();

        $this->post(route('checkout.store'))
            ->assertRedirect(route('cart.show'))
            ->assertSessionHas('error', 'This coupon has been used up.');

        $this->assertNull(session('cart.coupon'));
    }

    public function test_a_quick_order_shows_the_coupon_problem_on_the_coupon(): void
    {
        $product = Product::factory()->priced(1000)->withoutDomain()->create();
        Coupon::factory()->create(['code' => 'ONCE', 'value' => 100, 'max_uses' => 1]);
        $this->mock(OrderPlacer::class, fn (MockInterface $mock) => $mock->shouldReceive('place')->andThrow(CouponUnavailable::because('This coupon has been used up.')));

        $this->actingAs(Client::factory()->create(), 'web')
            ->post(route('order.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'coupon' => 'ONCE'])
            ->assertSessionHasErrors(['coupon' => 'This coupon has been used up.']);
    }

    /**
     * The cart checks the coupon, another checkout changes things, then this order is made.
     *
     * @param  array<string, mixed>  $limits
     * @param  callable(Coupon, Client): void  $otherCheckout
     */
    private function assertRefusedAfter(array $limits, callable $otherCheckout, string $reason): void
    {
        $coupon = Coupon::factory()->create(['code' => 'FIRSTFREE', 'value' => 100] + $limits);
        [$client, $lines, $checked] = $this->cartWith($coupon);

        $otherCheckout($coupon, $client);
        $orders = Order::query()->count();
        $uses = $coupon->fresh()->uses;
        $redemptions = $coupon->redemptions()->count();

        try {
            app(OrderPlacer::class)->place($client, $lines, coupon: $checked);
            $this->fail('The order should not be made.');
        } catch (CouponUnavailable $exception) {
            $this->assertSame($reason, $exception->reason());
        }

        $this->assertSame($orders, Order::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(0, Service::query()->count());
        $this->assertSame($uses, $coupon->fresh()->uses);
        $this->assertSame($redemptions, $coupon->redemptions()->count());
    }

    /**
     * @return array{0: Client, 1: Collection<int, CartLine>, 2: Coupon}
     */
    private function cartWith(Coupon $coupon): array
    {
        $product = Product::factory()->priced(1000)->withoutDomain()->create();
        $client = Client::factory()->create(['currency' => 'USD']);

        $cart = app(Cart::class);
        $cart->add($product, BillingCycle::Monthly, null);
        $cart->setCoupon($coupon->code);

        $checked = $cart->coupon('USD', $client);
        $lines = $cart->lines('USD', $client);

        $this->assertNotNull($checked, 'The cart accepts the coupon.');
        $this->assertSame(1000, $lines->first()->discount);

        return [$client, $lines, $checked];
    }
}
