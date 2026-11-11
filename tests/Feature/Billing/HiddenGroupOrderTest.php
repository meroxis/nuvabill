<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Hiding a product group from the store takes its products off sale: the cart, the price check and
 * the order form refuse them, as the store pages already do.
 */
class HiddenGroupOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_product_in_a_hidden_group_cannot_be_ordered(): void
    {
        Mail::fake();
        $product = Product::factory()->priced(899)->create(['requires_domain' => false, 'product_group_id' => ProductGroup::factory()->create(['is_visible' => false])]);
        $order = ['product_id' => $product->id, 'billing_cycle' => 'monthly'];

        $this->get(route('store.product', [$product->group, $product]))->assertNotFound();
        $this->post(route('cart.store'), $order)->assertSessionHasErrors(['product_id' => 'This product is not available right now.']);
        $this->postJson(route('store.api.quote'), $order)->assertUnprocessable()->assertJsonValidationErrors('product_id');
        $this->actingAs(Client::factory()->create(), 'web')->post(route('order.store'), $order)->assertSessionHasErrors('product_id');

        $this->assertSame(0, Order::query()->count());
    }

    public function test_the_order_form_makes_no_account_for_a_product_that_cannot_be_ordered(): void
    {
        Mail::fake();
        $product = Product::factory()->priced(899)->create(['requires_domain' => false, 'product_group_id' => ProductGroup::factory()->create(['is_visible' => false])]);

        $this->post(route('order.store'), [
            'product_id' => $product->id, 'billing_cycle' => 'monthly',
            'first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.test', 'country' => 'IQ', 'password' => 'secret-pass-1',
            'gateway' => 'banktransfer',
        ])->assertSessionHasErrors(['product_id' => 'This product is not available right now.']);

        $this->assertSame(0, Client::query()->count());
        $this->assertGuest('web');
    }

    public function test_a_cart_line_is_dropped_when_its_group_is_hidden_before_checkout(): void
    {
        Mail::fake();
        $product = Product::factory()->priced(899)->create(['name' => 'Legacy Plan', 'requires_domain' => false]);

        $this->actingAs(Client::factory()->create(), 'web')
            ->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('cart.show'));
        $this->get(route('cart.show'))->assertOk()->assertSee('Legacy Plan');

        $product->group->update(['is_visible' => false]);

        $this->get(route('cart.show'))->assertOk()->assertDontSee('Legacy Plan');
        $this->post(route('checkout.store'))->assertRedirect(route('cart.show'));
        $this->assertSame(0, Order::query()->count());

        // Shown again, it sells again.
        $product->group->update(['is_visible' => true]);
        $this->post(route('checkout.store'))->assertRedirect();
        $this->assertSame(1, Order::query()->count());
    }

    public function test_only_the_marketplace_store_sells_its_items_from_a_hidden_group(): void
    {
        $product = Product::factory()->priced(3900)->create([
            'requires_domain' => false,
            'module_config' => ['marketplace_item' => 'swift'],
            'product_group_id' => ProductGroup::factory()->create(['is_visible' => false]),
        ]);

        $this->assertFalse($product->isOrderable());

        config(['nuvabill.marketplace.store' => true]);
        $this->assertTrue($product->isOrderable());

        $product->update(['is_visible' => false]);
        $this->assertFalse($product->isOrderable());
    }
}
