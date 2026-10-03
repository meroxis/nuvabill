<?php

namespace Tests\Feature\Billing;

use App\Models\Client;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A guest who orders on the one-page order form also creates an account, so the "Create an
 * account" CAPTCHA and the sign-up rate limit apply to them as well.
 */
class QuickOrderSignUpCaptchaTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->priced(999)->create(['requires_domain' => false]);
    }

    public function test_a_guest_cannot_sign_up_through_the_order_form_without_the_sign_up_captcha(): void
    {
        $this->turnOnCaptcha(['client_register']);

        $this->post(route('order.store'), $this->guestOrder())->assertSessionHasErrors('captcha');

        $this->assertSame(0, Client::query()->count());
        $this->assertSame(0, Order::query()->count());
        $this->assertGuest('web');
    }

    public function test_a_guest_with_a_solved_captcha_signs_up_and_orders(): void
    {
        $this->turnOnCaptcha(['client_register']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->post(route('order.store'), $this->guestOrder() + ['cf-turnstile-response' => 'token'])->assertSessionHasNoErrors();

        $this->assertTrue(Client::query()->where('email', 'raz@example.test')->exists());
        $this->assertSame(1, Order::query()->count());
    }

    public function test_the_token_is_checked_only_once_when_checkout_is_protected_too(): void
    {
        $this->turnOnCaptcha(['checkout', 'client_register']);
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->post(route('order.store'), $this->guestOrder() + ['cf-turnstile-response' => 'token'])->assertSessionHasNoErrors();

        Http::assertSentCount(1);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_a_signed_in_client_does_not_need_the_sign_up_captcha(): void
    {
        $this->turnOnCaptcha(['client_register']);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);

        $this->actingAs($client, 'web')
            ->post(route('order.store'), ['product_id' => $this->product->id, 'billing_cycle' => 'monthly'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Order::query()->where('client_id', $client->id)->count());
    }

    public function test_guests_see_the_sign_up_captcha_on_the_order_form(): void
    {
        $this->turnOnCaptcha(['client_register']);

        $this->withViewErrors([]);
        $this->blade('<x-captcha form="checkout" />')->assertSee('cf-turnstile', false);

        $this->actingAs(Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']), 'web');
        $this->blade('<x-captcha form="checkout" />')->assertDontSee('cf-turnstile', false);
    }

    public function test_guest_orders_have_the_sign_up_rate_limit(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('order.store'), ['email' => 'raz@example.test'] + $this->guestOrder())->assertRedirect();
            $this->app['auth']->guard('web')->logout();
        }

        $this->post(route('order.store'), ['email' => 'mer@example.test'] + $this->guestOrder())->assertStatus(429);
    }

    /**
     * @param  list<string>  $forms
     */
    private function turnOnCaptcha(array $forms): void
    {
        $this->setSettings([
            'security.captcha_provider' => 'turnstile',
            'security.captcha_site_key' => 'site-key',
            'security.captcha_secret' => 'secret-key',
            'security.captcha_forms' => $forms,
            'security.captcha_checked_key' => 'site-key',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function guestOrder(): array
    {
        return [
            'product_id' => $this->product->id,
            'billing_cycle' => 'monthly',
            'first_name' => 'Raz',
            'last_name' => 'Las',
            'email' => 'raz@example.test',
            'country' => 'IQ',
            'password' => 'secret-pass-1',
        ];
    }
}
