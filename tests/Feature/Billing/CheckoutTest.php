<?php

namespace Tests\Feature\Billing;

use App\Billing\Cart;
use App\Billing\OrderPlacer;
use App\Billing\SoldOut;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_pages_show_visible_products(): void
    {
        $product = Product::factory()->priced(899)->create(['name' => 'Business Plan']);
        Product::factory()->priced(100)->create(['name' => 'Secret Plan', 'is_visible' => false]);

        $this->get(route('store.index'))->assertOk()->assertSee('Business Plan')->assertDontSee('Secret Plan')->assertSee('Powered by');
        $this->get(route('store.group', $product->group))->assertOk()->assertSee('Business Plan');
        $this->get(route('store.product', [$product->group, $product]))->assertOk()->assertSee('$8.99');
    }

    public function test_a_new_customer_can_order_hosting(): void
    {
        Mail::fake();
        $product = Product::factory()->priced(899, setupFee: 500)->create();

        $this->post(route('cart.store'), [
            'product_id' => $product->id,
            'billing_cycle' => 'monthly',
            'domain' => 'https://www.MyShop.com/',
        ])->assertRedirect(route('cart.show'));

        $this->get(route('cart.show'))->assertOk()->assertSee('myshop.com')->assertSee('$13.99');
        $this->get(route('checkout.show'))->assertOk()->assertSee('Create an account');

        $this->post(route('client.register'), [
            'first_name' => 'Raz',
            'last_name' => 'Las',
            'email' => 'raz@example.test',
            'country' => 'IQ',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect(route('checkout.show'));

        $response = $this->post(route('checkout.store'));

        $client = Client::query()->where('email', 'raz@example.test')->firstOrFail();
        $order = Order::query()->where('client_id', $client->id)->firstOrFail();
        $invoice = $order->invoice;
        $service = $order->services()->firstOrFail();

        $response->assertRedirect(route('client.invoices.show', $invoice));

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(ServiceStatus::Pending, $service->status);
        $this->assertSame('myshop.com', $service->domain);
        $this->assertSame(899, $service->recurring_amount);
        $this->assertSame(1399, $service->first_payment_amount);
        $this->assertSame(1399, $invoice->total);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertCount(2, $invoice->items);
        $this->assertStringStartsWith('INV-', $invoice->number);
        $this->assertTrue($invoice->items->first()->period_start->isToday());

        $this->get(route('cart.show'))->assertSee('Your cart is empty');
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail) => $mail->hasTo('raz@example.test') && str_contains($mail->subjectLine, $order->number));
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail) => $mail->hasTo('billing@example.com'));
    }

    public function test_a_domain_is_required_when_the_product_needs_one(): void
    {
        $product = Product::factory()->priced()->create(['requires_domain' => true]);

        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])
            ->assertSessionHasErrors('domain');

        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'not a domain'])
            ->assertSessionHasErrors('domain');
    }

    public function test_only_offered_billing_cycles_can_be_ordered(): void
    {
        $product = Product::factory()->priced(899, BillingCycle::Monthly)->withoutDomain()->create();

        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'annually'])
            ->assertSessionHasErrors('billing_cycle');
    }

    public function test_a_free_product_is_activated_straight_away(): void
    {
        $client = Client::factory()->create();
        $product = Product::factory()->priced(0, BillingCycle::Free)->withoutDomain()->create();

        $this->actingAs($client, 'web');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'free']);
        $this->post(route('checkout.store'))->assertRedirect(route('client.dashboard'));

        $order = Order::query()->firstOrFail();

        $this->assertSame(InvoiceStatus::Paid, $order->invoice->status);
        $this->assertSame(ServiceStatus::Active, $order->services()->first()->status);
        $this->assertSame(OrderStatus::Active, $order->fresh()->status);
    }

    public function test_one_checkout_cannot_take_more_than_is_in_stock(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1]);
        $this->actingAs(Client::factory()->create(), 'web');

        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertSessionHasNoErrors();
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])
            ->assertSessionHasErrors(['product_id' => 'Your cart already has all of this product that is left.']);

        // Even with two lines in the cart, only what is in stock is ordered.
        app(Cart::class)->add($product, BillingCycle::Monthly, null);
        $this->assertCount(2, session('cart.items'));
        $this->assertCount(1, app(Cart::class)->lines('USD'));

        $this->post(route('checkout.store'))->assertRedirect();
        $this->assertSame(1, $product->services()->count());
        $this->assertFalse($product->fresh()->isInStock());
    }

    public function test_an_order_that_lost_the_last_one_to_another_checkout_makes_nothing(): void
    {
        $product = Product::factory()->priced()->withoutDomain()->create(['stock' => 1, 'name' => 'Last Server']);
        $client = Client::factory()->create(['currency' => 'USD']);
        $cart = app(Cart::class);
        $cart->add($product, BillingCycle::Monthly, null);
        $lines = $cart->lines('USD');

        // Another checkout takes the last one before this order is made.
        Service::factory()->create(['product_id' => $product->id]);

        try {
            app(OrderPlacer::class)->place($client, $lines);
            $this->fail('The order should not be made.');
        } catch (SoldOut $exception) {
            $this->assertSame('Last Server is sold out. Remove it from your cart.', $exception->getMessage());
        }

        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame(1, $product->services()->count());
    }

    public function test_terms_must_be_accepted_when_configured(): void
    {
        $this->setSettings(['orders.accept_terms_url' => 'https://example.test/terms']);
        $product = Product::factory()->priced()->withoutDomain()->create();

        $this->actingAs(Client::factory()->create(), 'web');
        $this->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly']);

        $this->post(route('checkout.store'))->assertSessionHasErrors('accept_terms');
        $this->post(route('checkout.store'), ['accept_terms' => '1'])->assertRedirect();
        $this->assertSame(1, Order::query()->count());
    }
}
