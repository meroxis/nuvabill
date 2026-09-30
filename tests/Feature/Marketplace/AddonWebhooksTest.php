<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\ExtensionManager;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The add-on hook from Nuvabill 0.6.10: addresses other services post to, such as a chat bridge.
 */
class AddonWebhooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
    }

    /**
     * The app registered its routes before the test switched the add-ons on, so load them again.
     */
    private function loadRoutes(): void
    {
        Route::prefix('webhooks/addons')->group(fn () => app(ExtensionManager::class)->registerWebhookRoutes());
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }

    public function test_an_addon_with_the_webhook_permission_takes_posts_without_a_form_token_or_cookies(): void
    {
        app(ExtensionManager::class)->saveSettings('hooks', [], true);
        $this->loadRoutes();

        $url = route('webhooks.addon.hooks.ping', 'secret123');
        $this->assertStringEndsWith('/webhooks/addons/hooks/ping/secret123', $url);

        $response = $this->postJson($url, ['text' => 'hello'])->assertOk()->assertExactJson(['key' => 'secret123', 'got' => 'hello']);
        $this->assertEmpty($response->headers->getCookies(), 'Webhooks get no session cookie');
    }

    public function test_addons_without_the_permission_or_switched_off_get_no_webhook_address(): void
    {
        // Switched off: no address.
        $this->loadRoutes();
        $this->postJson('/webhooks/addons/hooks/ping/secret123', ['text' => 'hello'])->assertNotFound();

        // Switched on, but its manifest does not ask for "webhook".
        app(ExtensionManager::class)->saveSettings('homepage', [], true);
        $this->loadRoutes();
        $this->postJson('/webhooks/addons/homepage/ping')->assertNotFound();
    }
}
