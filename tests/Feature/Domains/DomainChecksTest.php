<?php

namespace Tests\Feature\Domains;

use App\Domains\Rdap;
use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\TldPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DomainChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_failed_rdap_bootstrap_is_not_downloaded_again_for_every_domain(): void
    {
        $this->sellTld('io', 3900);
        $this->sellTld('dev', 1500);
        $this->sellTld('app', 1800);
        Http::fake([Rdap::BOOTSTRAP_URL => Http::response('Service unavailable', 503)]);

        $this->get(route('store.domains', ['q' => 'my-name']))
            ->assertOk()
            ->assertSee('my-name.io')
            ->assertSee('my-name.app');
        Http::assertSentCount(1);

        // The next search does not wait for IANA again either.
        $this->get(route('store.domains', ['q' => 'other-name']))->assertOk()->assertSee('other-name.dev');
        Http::assertSentCount(1);
    }

    public function test_subdomains_cannot_be_registered_or_transferred(): void
    {
        Http::fake();
        $this->sellTld('com', 1299);

        $this->post(route('cart.domains.store'), ['domain' => 'blog.mybrand.com', 'action' => 'register'])
            ->assertSessionHasErrors(['domain' => 'Enter a domain like example.com.']);
        $this->post(route('cart.domains.store'), ['domain' => 'https://Shop.MyBrand.com/', 'action' => 'transfer', 'epp_code' => 'Secret#123'])
            ->assertSessionHasErrors(['domain' => 'Enter a domain like example.com.']);
        $this->assertSame([], session('cart.items', []));

        $this->post(route('cart.domains.store'), ['domain' => 'mybrand.com', 'action' => 'register'])->assertSessionHasNoErrors();
        $this->assertSame(['mybrand.com'], array_column(session('cart.items'), 'domain'));
    }

    public function test_a_subdomain_can_be_hosted_but_not_registered_with_the_hosting(): void
    {
        Http::fake();
        $this->sellTld('com', 1299);
        $product = Product::factory()->priced(899)->create();
        $order = ['product_id' => $product->id, 'billing_cycle' => BillingCycle::Monthly->value, 'domain' => 'blog.mybrand.com'];

        $this->post(route('cart.store'), $order + ['register_domain' => 1])->assertSessionHasErrors(['domain' => 'Enter a domain like example.com.']);
        $this->assertSame([], session('cart.items', []));

        $this->post(route('cart.store'), $order)->assertSessionHasNoErrors()->assertRedirect(route('cart.show'));
        $this->assertSame(['blog.mybrand.com'], array_column(session('cart.items'), 'domain'));
    }

    public function test_the_quick_order_refuses_to_register_a_subdomain(): void
    {
        Http::fake();
        $this->sellTld('com', 1299);
        $this->sellTld('co.uk', 899);
        $product = Product::factory()->priced(899)->create();
        $order = ['product_id' => $product->id, 'billing_cycle' => BillingCycle::Monthly->value, 'domain' => 'shop.example.com'];

        $this->postJson(route('store.api.quote'), $order + ['domain_action' => 'register'])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson(route('store.api.quote'), $order + ['domain_action' => 'transfer', 'epp_code' => 'Secret#123'])->assertUnprocessable()->assertJsonValidationErrors('domain');
        $this->postJson(route('store.api.quote'), $order + ['domain_action' => 'own'])->assertOk();
        $this->postJson(route('store.api.quote'), ['domain' => 'shop.co.uk', 'domain_action' => 'register'] + $order)->assertOk();
    }

    private function sellTld(string $tld, int $register): TldPrice
    {
        return TldPrice::create([
            'tld' => $tld,
            'currency' => 'USD',
            'register_price' => $register,
            'renew_price' => $register,
            'transfer_price' => $register,
        ]);
    }
}
