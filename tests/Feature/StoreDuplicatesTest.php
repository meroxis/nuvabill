<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\HomePageController;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Seo\SeoTranslations;
use App\Seo\Sitemap;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Store pages that show the same thing as another page: plan pages and the store overview
 * behind a website from an add-on (0.7.4).
 */
class StoreDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);
        $this->product = Product::factory()->priced(500)->create(['name' => 'Starter']);
        $this->group = $this->product->group;
    }

    private function url(string $path): string
    {
        return url('/').'/'.$path;
    }

    public function test_plan_pages_send_search_engines_to_their_group_by_default(): void
    {
        $groupPath = 'store/'.$this->group->slug;
        SeoTranslations::save($this->group, ['de' => ['title' => 'Webhosting', 'description' => 'Webhosting mit kostenlosem SSL.']]);
        SeoTranslations::save($this->product, ['ar' => ['title' => 'المبتدئ', 'description' => 'باقة صغيرة للبداية.']]);

        $html = $this->get('/'.$this->product->storePath())->assertOk()->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.$this->url($groupPath).'">', $html);
        $this->assertStringNotContainsString('hreflang=', $html);
        $this->assertStringNotContainsString('noindex', $html);
        // The plan keeps its own title and its price data.
        $this->assertStringContainsString('<title>Starter · ', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);

        // A language the group is written in keeps its language; others go to the main language.
        $this->get('/'.$this->product->storePath().'?lang=de')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->url($groupPath).'?lang=de">', false);
        $this->get('/'.$this->product->storePath().'?lang=ar')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->url($groupPath).'">', false)
            ->assertSee('<title>المبتدئ</title>', false);

        $xml = $this->get(route('seo.sitemap'))->assertOk()->getContent();
        $this->assertStringContainsString('<loc>'.$this->url($groupPath).'</loc>', $xml);
        $this->assertStringNotContainsString($this->product->storePath(), $xml);
    }

    public function test_a_plan_whose_group_is_hidden_from_search_engines_is_hidden_too(): void
    {
        $this->group->update(['seo_hidden' => true]);

        $this->get('/'.$this->product->storePath())->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function test_plan_pages_can_be_pages_of_their_own(): void
    {
        app(Settings::class)->setMany(['seo.product_pages' => true]);

        $this->get('/'.$this->product->storePath())->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->url($this->product->storePath()).'">', false);

        $this->assertStringContainsString('<loc>'.$this->url($this->product->storePath()).'</loc>', $this->get(route('seo.sitemap'))->getContent());
    }

    public function test_staff_choose_whether_plans_are_pages_of_their_own(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.products.edit', $this->product))->assertOk()
            ->assertSee('Search engines are sent to the group page for this plan.')
            ->assertSee(route('admin.settings.seo.edit'), false);
        $this->get(route('admin.settings.seo.edit'))->assertOk()->assertSee('List each plan as its own page');

        $this->put(route('admin.settings.seo.update'), ['product_pages' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(setting('seo.product_pages'));

        $this->get(route('admin.products.edit', $this->product))->assertOk()
            ->assertDontSee('Search engines are sent to the group page for this plan.');
    }

    public function test_the_store_overview_is_hidden_when_a_website_shows_the_home_page(): void
    {
        $this->switchOnFixtureAddon();

        $this->get('/store/'.$this->group->slug)->assertOk()->assertDontSee('noindex', false);
        $this->get('/store')->assertOk()->assertSee('<meta name="robots" content="noindex, follow">', false);

        $xml = $this->get(route('seo.sitemap'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<loc>'.$this->url('store').'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.$this->url('store/'.$this->group->slug).'</loc>', $xml);
    }

    public function test_without_a_website_the_store_home_stays_indexable(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('noindex', $html);
        $this->assertStringContainsString('"@type":"Organization"', $html);
        $this->assertStringContainsString('<loc>'.$this->url('').'</loc>', $this->get(route('seo.sitemap'))->getContent());
    }

    private function switchOnFixtureAddon(): void
    {
        $this->seed(DefaultDataSeeder::class);
        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->saveSettings('homepage', [], true);
        Sitemap::forget();

        // The app registered its routes before the add-on was switched on, so load them again.
        Route::middleware('web')->group(fn () => HomePageController::routes());
        Route::middleware('web')->group(fn () => app(ExtensionManager::class)->registerPublicRoutes());
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }
}
