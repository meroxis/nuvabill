<?php

namespace Tests\Feature\Billing;

use App\Billing\ExchangeRates;
use App\Enums\BillingCycle;
use App\Http\Controllers\Store\QuickOrderController;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QuickOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_quote_prices_the_choices_with_coupon_add_ons_and_converted_amounts(): void
    {
        $product = Product::factory()->priced(999)->priced(9588, BillingCycle::Annually)->create();
        $backups = ProductAddon::factory()->priced(2400, BillingCycle::Annually)->create(['name' => 'Daily backups']);
        Coupon::factory()->create(['code' => 'WELCOME20', 'value' => 20]);
        $this->enableGateway('banktransfer', ['instructions' => 'Pay to our bank.']);
        $this->enableGateway('wayl', ['api_token' => 'token']);
        app(ExchangeRates::class)->save(['IQD' => 1310]);

        $this->postJson(route('store.api.quote'), [
            'product_id' => $product->id, 'billing_cycle' => 'annually', 'addons' => [$backups->id],
            'domain_action' => 'own', 'domain' => 'razstudio.com', 'coupon' => 'welcome20',
        ])->assertOk()
            ->assertJsonPath('total', 9588 + 2400 - 1918)
            ->assertJsonPath('total_label', '$100.70')
            ->assertJsonPath('coupon.code', 'WELCOME20')
            ->assertJsonPath('saving', '$24.00')
            ->assertJsonPath('lines.0.addons.0', 'Daily backups')
            ->assertJsonPath('payment.1.slug', 'wayl')
            ->assertJsonPath('payment.1.pays', fn (string $pays): bool => str_contains($pays, '131,917'));
    }

    public function test_order_forms_get_the_first_quote_with_the_page_exactly_as_the_store_would_answer(): void
    {
        $product = Product::factory()->priced(999)->priced(9588, BillingCycle::Annually)->create();
        $this->enableGateway('banktransfer', ['instructions' => 'Pay to our bank.']);
        $controller = app(QuickOrderController::class);

        $answer = $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'annually'])->assertOk()->json();

        $this->assertSame($answer, json_decode((string) json_encode($controller->firstQuote($product->id, 'annually')), true));
        $this->assertNull($controller->firstQuote($product->id, 'biennially'));
        $this->assertNull($controller->firstQuote($product->id, 'every-day'));
        $this->assertNull($controller->firstQuote(999999, 'annually'));
    }

    public function test_a_new_client_signs_up_orders_and_goes_to_pay_on_one_page(): void
    {
        $product = Product::factory()->priced(999)->create();
        $this->enableGateway('banktransfer', ['instructions' => 'Pay to our bank.']);

        $response = $this->post(route('order.store'), [
            'product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain_action' => 'own', 'domain' => 'razstudio.com',
            'first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.test', 'country' => 'IQ', 'password' => 'secret-pass-1',
            'gateway' => 'banktransfer',
        ]);

        $client = Client::query()->where('email', 'raz@example.test')->sole();
        $order = Order::query()->sole();

        $this->assertAuthenticatedAs($client, 'web');
        $this->assertSame($client->id, $order->client_id);
        $this->assertSame('razstudio.com', $order->services()->sole()->domain);
        $response->assertRedirect(route('client.invoices.show', $order->invoice))->assertSessionHas('payment_instructions');
    }

    public function test_a_signed_in_client_orders_without_signing_up_again(): void
    {
        $product = Product::factory()->priced(999)->create(['requires_domain' => false]);
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('order.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])
            ->assertRedirect(route('client.invoices.show', Order::query()->sole()->invoice));

        $this->assertSame(1, Client::query()->count());
    }
}
