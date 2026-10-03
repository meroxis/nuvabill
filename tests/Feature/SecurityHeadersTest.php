<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\Cloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_tell_browsers_to_block_framing_sniffing_and_outside_scripts(): void
    {
        $this->get(route('store.index'))
            ->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeaderMissing('Strict-Transport-Security')
            ->assertHeader('Content-Security-Policy');

        $this->get(str_replace('http://', 'https://', route('store.index')))
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_cloudflare_web_analytics_may_load_only_on_pages_served_through_cloudflare(): void
    {
        $this->assertStringNotContainsString('cloudflareinsights.com', (string) $this->get(route('store.index'))->headers->get('Content-Security-Policy'));

        $policy = (string) $this->withHeaders(['CF-Ray' => '8d1f2a3b4c5d6e7f-AMS'])->get(route('store.index'))->headers->get('Content-Security-Policy');

        $this->assertStringContainsString('script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' https://static.cloudflareinsights.com', $policy);
        $this->assertStringContainsString('connect-src \'self\' https://cloudflareinsights.com', $policy);
    }

    public function test_downloads_get_no_page_policy(): void
    {
        $client = Client::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);

        $this->actingAs($client, 'web')
            ->get(route('client.invoices.pdf', $invoice))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_behind_cloudflare_the_visitor_ip_is_used_but_the_host_cannot_be_faked(): void
    {
        config(['trustedproxy.proxies' => Cloudflare::IP_RANGES]);
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);
        $dashboard = route('admin.dashboard');

        $this->withServerVariables(['REMOTE_ADDR' => '173.245.48.10'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9', 'X-Forwarded-Host' => 'evil.test'])
            ->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password'])
            ->assertHeader('Location', $dashboard);

        $this->assertSame('203.0.113.9', $admin->fresh()->last_login_ip);
    }

    public function test_forwarded_ips_from_unknown_proxies_are_ignored(): void
    {
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
            ->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password']);

        $this->assertSame('198.51.100.7', $admin->fresh()->last_login_ip);
    }

    public function test_a_forge_host_name_does_not_make_forwarded_ips_trusted(): void
    {
        config(['trustedproxy.proxies' => (require config_path('trustedproxy.php'))['proxies']]);
        $admin = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'owner@example.test']);

        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
            ->withHeaders(['X-Forwarded-For' => '203.0.113.9'])
            ->post('http://x.on-forge.com/'.config('nuvabill.admin_path').'/login', ['email' => 'owner@example.test', 'password' => 'password']);

        $this->assertSame('198.51.100.7', $admin->fresh()->last_login_ip);
    }

    /**
     * @param  list<string>|string|null  $expected
     */
    #[DataProvider('proxySettings')]
    public function test_the_trusted_proxy_setting_is_read_from_the_environment(string $value, array|string|null $expected): void
    {
        $_SERVER['NUVABILL_TRUSTED_PROXIES'] = $value;

        try {
            $this->assertSame($expected, (require config_path('trustedproxy.php'))['proxies']);
        } finally {
            unset($_SERVER['NUVABILL_TRUSTED_PROXIES']);
        }
    }

    /**
     * @return array<string, array{string, list<string>|string|null}>
     */
    public static function proxySettings(): array
    {
        return [
            'not set' => ['', []],
            'cloudflare' => ['cloudflare', Cloudflare::IP_RANGES],
            'any proxy' => ['*', '*'],
            'a list' => ['10.0.0.1, 10.1.0.0/16', ['10.0.0.1', '10.1.0.0/16']],
        ];
    }
}
