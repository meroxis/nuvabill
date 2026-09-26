<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LegalLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_save_the_privacy_policy_link(): void
    {
        $this->signInAdmin();

        $this->put(route('admin.settings.update'), $this->generalSettings(['terms_url' => 'https://host.test/terms', 'privacy_url' => 'https://host.test/privacy']))
            ->assertSessionHas('status');

        $this->assertSame('https://host.test/privacy', setting('company.privacy_url'));
        $this->assertSame('https://host.test/terms', setting('orders.accept_terms_url'));

        $this->put(route('admin.settings.update'), $this->generalSettings(['privacy_url' => 'not a link']))
            ->assertSessionHasErrors('privacy_url');
    }

    public function test_clients_see_the_terms_and_privacy_links(): void
    {
        $this->get(route('client.register'))->assertOk()->assertDontSee('Privacy policy');

        app(Settings::class)->setMany([
            'orders.accept_terms_url' => 'https://host.test/terms',
            'company.privacy_url' => 'https://host.test/privacy',
        ]);

        $this->get(route('client.register'))
            ->assertSee('By creating an account, you agree to our', false)
            ->assertSee('href="https://host.test/terms"', false)
            ->assertSee('href="https://host.test/privacy"', false);

        $this->actingAs(Client::factory()->create(), 'web')
            ->get(route('client.dashboard'))
            ->assertSee('href="https://host.test/privacy"', false);
    }

    public function test_only_the_privacy_link_shows_when_there_are_no_terms(): void
    {
        app(Settings::class)->set('company.privacy_url', 'https://host.test/privacy');

        $this->get(route('client.register'))
            ->assertSee('href="https://host.test/privacy"', false)
            ->assertDontSee('Terms of service');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function generalSettings(array $overrides): array
    {
        return $overrides + [
            'company_name' => 'YourHost',
            'company_email' => 'billing@host.test',
            'currency' => 'USD',
            'renewal_days_before' => 7,
            'payment_terms_days' => 7,
            'suspend_days' => 5,
            'terminate_days' => 30,
            'accent' => '#0B7A70',
            'theme' => 'nova',
        ];
    }
}
