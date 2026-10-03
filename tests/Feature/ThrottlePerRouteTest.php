<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every "throttle:N,M" route has its own counter, so using one page never blocks another.
 */
class ThrottlePerRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_price_checks_do_not_use_up_the_order_limit(): void
    {
        $product = Product::factory()->priced(999)->create(['requires_domain' => false]);

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertOk();
        }

        $this->post(route('order.store'), [
            'product_id' => $product->id, 'billing_cycle' => 'monthly',
            'first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.test', 'country' => 'IQ', 'password' => 'secret-pass-1',
        ])->assertRedirect();

        $this->assertTrue(Client::query()->where('email', 'raz@example.test')->exists());
    }

    public function test_a_clients_requests_do_not_throttle_the_staff_member_with_the_same_id(): void
    {
        $product = Product::factory()->priced(999)->create(['requires_domain' => false]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        $admin = Admin::factory()->create(['name' => 'Raz']);
        $this->assertSame($client->id, $admin->id);

        $this->actingAs($client, 'web');

        for ($i = 0; $i < 10; $i++) {
            $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly'])->assertOk();
        }

        $this->signInAdmin($admin);
        $this->post(route('admin.profile.api-keys.store'), ['name' => 'Accounting'])->assertRedirect()->assertSessionHas('new_api_key');
    }

    public function test_each_route_still_has_its_limit(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.test']);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('client.login'), ['email' => $client->email, 'password' => 'wrong-password'])->assertSessionHasErrors();
        }

        $this->post(route('client.login'), ['email' => $client->email, 'password' => 'wrong-password'])->assertStatus(429);
    }
}
