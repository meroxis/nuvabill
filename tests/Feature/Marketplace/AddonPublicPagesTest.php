<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\HomePageController;
use App\Seo\Sitemap;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The add-on hooks from Nuvabill 0.6.1: pages for visitors, the home page, and sitemap.xml.
 */
class AddonPublicPagesTest extends TestCase
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
     * The app registered its routes before the test switched the add-on on, so load them again.
     */
    private function loadRoutes(): void
    {
        Route::middleware('web')->group(fn () => HomePageController::routes());
        Route::middleware('web')->group(fn () => app(ExtensionManager::class)->registerPublicRoutes());
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }

    public function test_an_addon_can_show_the_home_page_and_the_store_moves_to_store(): void
    {
        app(ExtensionManager::class)->saveSettings('homepage', [], true);
        $this->loadRoutes();

        $this->get('/')->assertOk()->assertSee('Add-on home page');
        $this->assertSame(url('store'), route('store.index'));
        $this->get('/store')->assertOk()->assertDontSee('Add-on home page');
        $this->get('/hello')->assertOk()->assertSee('Hello from the add-on');
    }

    public function test_addon_pages_never_replace_nuvabill_pages(): void
    {
        app(ExtensionManager::class)->saveSettings('homepage', [], true);
        $this->loadRoutes();

        // "domains" and "cart" are Nuvabill pages, loaded before the add-on's catch-all page.
        $this->get('/domains')->assertOk()->assertDontSee('Add-on page');
        $this->get('/cart')->assertOk()->assertDontSee('Add-on page');
        $this->get('/about-us')->assertOk()->assertSee('Add-on page about-us');
    }

    public function test_without_the_addon_the_store_is_the_home_page(): void
    {
        $this->loadRoutes();

        $this->assertSame(url('/'), route('store.index'));
        $this->get('/store')->assertRedirect('/');
        $this->get('/hello')->assertNotFound();
    }

    public function test_addon_pages_are_in_the_sitemap(): void
    {
        app(ExtensionManager::class)->saveSettings('homepage', [], true);
        Sitemap::forget();

        $xml = app(Sitemap::class)->xml();

        $this->assertStringContainsString('<loc>'.url('hello').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('store').'</loc>', $xml);
    }

    public function test_an_addon_without_the_public_page_permission_gets_no_pages(): void
    {
        $manifest = base_path('tests/Fixtures/extensions/addons/homepage/extension.json');
        $original = file_get_contents($manifest);
        file_put_contents($manifest, str_replace('["public-page"]', '[]', $original));

        try {
            $this->app->forgetInstance(ExtensionManager::class);
            app(ExtensionManager::class)->saveSettings('homepage', [], true);

            $this->assertNull(app(ExtensionManager::class)->homePageAddon());
            $this->assertSame([], app(ExtensionManager::class)->sitemapPages());
        } finally {
            file_put_contents($manifest, $original);
        }
    }
}
