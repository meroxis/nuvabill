<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\HomePageController;
use App\Models\Announcement;
use App\Models\KbArticle;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SeoTranslation;
use App\Seo\HeadTags;
use App\Seo\Seo;
use App\Seo\SeoTranslations;
use App\Seo\Sitemap;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Search texts in every language, and only the language versions that are really translated
 * offered to search engines (0.7.4).
 */
class SeoLanguagesTest extends TestCase
{
    use RefreshDatabase;

    private ProductGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);
        $product = Product::factory()->priced(500)->create();
        $this->group = $product->group;
        $this->group->update(['seo_title' => 'Fast web hosting', 'seo_description' => 'Web hosting with free SSL and daily backups.']);
    }

    private function translateGroupToGerman(): void
    {
        SeoTranslations::save($this->group, ['de' => ['title' => 'Schnelles Webhosting', 'description' => 'Webhosting mit kostenlosem SSL und täglichen Backups.']]);
    }

    private function groupUrl(?string $lang = null): string
    {
        return url('/').'/store/'.$this->group->slug.($lang !== null ? '?lang='.$lang : '');
    }

    public function test_a_group_page_shows_its_search_text_in_the_visitors_language(): void
    {
        $this->translateGroupToGerman();

        $this->get('/store/'.$this->group->slug.'?lang=de')->assertOk()
            ->assertSee('<title>Schnelles Webhosting</title>', false)
            ->assertSee('<meta name="description" content="Webhosting mit kostenlosem SSL und täglichen Backups.">', false)
            ->assertSee('<link rel="canonical" href="'.$this->groupUrl('de').'">', false);

        // The main language keeps its own text.
        $this->get('/store/'.$this->group->slug.'?lang=en')->assertOk()->assertSee('<title>Fast web hosting</title>', false);
    }

    public function test_only_translated_languages_get_language_links(): void
    {
        $this->translateGroupToGerman();

        $this->get('/store/'.$this->group->slug)->assertOk()
            ->assertSee('<link rel="alternate" hreflang="en" href="'.$this->groupUrl().'">', false)
            ->assertSee('<link rel="alternate" hreflang="de" href="'.$this->groupUrl('de').'">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.$this->groupUrl().'">', false)
            ->assertDontSee('hreflang="ar"', false);
    }

    public function test_an_untranslated_language_version_points_to_the_main_language(): void
    {
        $this->translateGroupToGerman();

        $this->get('/store/'.$this->group->slug.'?lang=ar')->assertOk()
            ->assertSee('<html lang="ar"', false)
            ->assertSee('<link rel="canonical" href="'.$this->groupUrl().'">', false)
            ->assertDontSee('hreflang=', false);
    }

    public function test_with_one_ready_language_there_are_no_language_links(): void
    {
        $this->get('/store/'.$this->group->slug)->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->groupUrl().'">', false)
            ->assertDontSee('hreflang=', false);
    }

    public function test_turning_off_ready_only_links_every_language_again(): void
    {
        app(Settings::class)->setMany(['seo.ready_languages_only' => false]);

        $this->get('/store/'.$this->group->slug)->assertOk()
            ->assertSee('<link rel="alternate" hreflang="de" href="'.$this->groupUrl('de').'">', false)
            ->assertSee('<link rel="alternate" hreflang="ar" href="'.$this->groupUrl('ar').'">', false);

        $this->get('/store/'.$this->group->slug.'?lang=ar')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->groupUrl('ar').'">', false);
    }

    public function test_the_sitemap_lists_only_ready_languages_and_x_default(): void
    {
        $this->translateGroupToGerman();

        $xml = $this->get(route('seo.sitemap'))->assertOk()->getContent();
        $this->assertNotFalse(simplexml_load_string($xml));
        $entry = $this->sitemapEntry($xml, $this->groupUrl());

        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="de" href="'.$this->groupUrl('de').'"/>', $entry);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="en" href="'.$this->groupUrl().'"/>', $entry);
        $this->assertStringContainsString('<xhtml:link rel="alternate" hreflang="x-default" href="'.$this->groupUrl().'"/>', $entry);
        $this->assertStringNotContainsString('hreflang="ar"', $entry);

        // The home page has no texts in other languages yet: no language links.
        $this->assertStringNotContainsString('hreflang=', $this->sitemapEntry($xml, url('/').'/'));
    }

    public function test_a_translation_that_copies_the_main_description_is_not_ready(): void
    {
        SeoTranslations::save($this->group, ['de' => ['title' => 'Schnelles Webhosting', 'description' => '  web hosting with FREE SSL and   daily backups. ']]);

        $this->assertSame([], SeoTranslations::readyLocales($this->group, 'Fast web hosting', 'Web hosting with free SSL and daily backups.'));
        $this->get('/store/'.$this->group->slug.'?lang=de')->assertOk()
            ->assertSee('<link rel="canonical" href="'.$this->groupUrl().'">', false)
            ->assertDontSee('hreflang=', false);

        // A title alone is not enough either.
        SeoTranslations::save($this->group, ['de' => ['title' => 'Schnelles Webhosting', 'description' => '']]);
        $this->assertSame([], SeoTranslations::readyLocales($this->group, 'Fast web hosting', 'Web hosting with free SSL and daily backups.'));
    }

    public function test_search_texts_are_saved_only_for_other_switched_on_languages(): void
    {
        SeoTranslations::save($this->group, [
            'de' => ['title' => "  Schnelles \n Webhosting ", 'description' => str_repeat('a', 400)],
            'en' => ['title' => 'Main language', 'description' => 'Not saved'],
            'fr' => ['title' => 'Langue éteinte', 'description' => 'Pas enregistré'],
        ]);

        $this->assertSame(['de'], SeoTranslation::query()->pluck('locale')->all());
        $this->assertSame('Schnelles Webhosting', SeoTranslations::title($this->group, 'de'));
        $this->assertSame(320, mb_strlen((string) SeoTranslations::description($this->group, 'de')));
        $this->assertNull(SeoTranslations::title($this->group, 'en'));

        // Both texts empty removes the language.
        SeoTranslations::save($this->group, ['de' => ['title' => ' ', 'description' => null]]);
        $this->assertSame(0, SeoTranslation::query()->count());
    }

    public function test_help_articles_and_news_offer_only_the_languages_they_are_written_in(): void
    {
        $article = KbArticle::factory()->create();
        $article->saveTranslation('de', 'Wie ändere ich das?', 'Öffnen Sie den Kundenbereich.');
        // A title alone is not a translated article, but it is enough for a category.
        $article->saveTranslation('ar', 'كيف أغير ذلك؟', null);
        $article->category->saveTranslation('ar', 'مساعدة', null);
        $news = Announcement::factory()->create();
        $news->saveTranslation('ar', 'خبر', 'نص الخبر.');
        $articleUrl = url('/').'/knowledgebase/'.$article->category->slug.'/'.$article->slug;
        $categoryUrl = url('/').'/knowledgebase/'.$article->category->slug;
        $newsUrl = url('/').'/announcements/'.$news->slug;

        $this->get($articleUrl)->assertOk()
            ->assertSee('<link rel="alternate" hreflang="de" href="'.$articleUrl.'?lang=de">', false)
            ->assertDontSee('hreflang="ar"', false);
        $this->get($articleUrl.'?lang=ar')->assertOk()->assertSee('<link rel="canonical" href="'.$articleUrl.'">', false);
        $this->get($categoryUrl)->assertOk()
            ->assertSee('hreflang="ar"', false)
            ->assertDontSee('hreflang="de"', false);
        $this->get($newsUrl)->assertOk()
            ->assertSee('<link rel="alternate" hreflang="ar" href="'.$newsUrl.'?lang=ar">', false)
            ->assertDontSee('hreflang="de"', false);

        $xml = app(Sitemap::class)->xml();
        $this->assertStringContainsString('hreflang="de"', $this->sitemapEntry($xml, $articleUrl));
        $this->assertStringNotContainsString('hreflang="ar"', $this->sitemapEntry($xml, $articleUrl));
        $this->assertStringContainsString('hreflang="ar"', $this->sitemapEntry($xml, $categoryUrl));
        $this->assertStringContainsString('hreflang="ar"', $this->sitemapEntry($xml, $newsUrl));
        $this->assertStringNotContainsString('hreflang="de"', $this->sitemapEntry($xml, $newsUrl));
    }

    public function test_an_addon_page_can_say_which_languages_are_ready(): void
    {
        $this->switchOnFixtureAddon();

        $this->get('/ready-page')->assertOk()
            ->assertSee('<link rel="alternate" hreflang="en" href="'.url('/').'/ready-page">', false)
            ->assertSee('<link rel="alternate" hreflang="de" href="'.url('/').'/ready-page?lang=de">', false)
            ->assertDontSee('hreflang="ar"', false);

        $entry = $this->sitemapEntry(app(Sitemap::class)->xml(), url('/').'/ready-page');
        $this->assertStringContainsString('hreflang="de" href="'.url('/').'/ready-page?lang=de"', $entry);
        $this->assertStringContainsString('hreflang="x-default" href="'.url('/').'/ready-page"', $entry);
        $this->assertStringNotContainsString('hreflang="ar"', $entry);
        $this->assertStringNotContainsString('xx', $entry);
    }

    public function test_a_page_can_point_search_engines_to_another_page(): void
    {
        $this->switchOnFixtureAddon();

        $this->get('/copy-page')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'/hello">', false)
            ->assertSee('<meta property="og:url" content="'.url('/').'/hello">', false)
            ->assertDontSee('hreflang=', false);

        // A language version points to the same language of the other page.
        $this->get('/copy-page?lang=de')->assertOk()
            ->assertSee('<link rel="canonical" href="'.url('/').'/hello?lang=de">', false)
            ->assertDontSee('hreflang=', false);
    }

    public function test_a_page_cannot_point_search_engines_to_another_site(): void
    {
        foreach (['https://evil.example/x', '//evil.example/x', '/\\evil.example', 'javascript:alert(1)', "store\nx", 'store?x=1'] as $path) {
            $this->assertNull((new Seo)->setCanonicalPath($path)->canonicalPath(), $path);
        }

        $this->assertSame('store/web-hosting', (new Seo)->setCanonicalPath('/store/web-hosting/')->canonicalPath());
    }

    public function test_hidden_pages_can_still_let_search_engines_follow_their_links(): void
    {
        $page = '<html><head><title>x</title></head><body></body></html>';
        $request = Request::create('/knowledgebase');

        $this->assertStringContainsString('<meta name="robots" content="noindex, follow">', (new HeadTags((new Seo)->hideButFollowLinks()))->apply($page, $request));
        // hide() wins, whatever the order.
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (new HeadTags((new Seo)->hideButFollowLinks()->hide()))->apply($page, $request));
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', (new HeadTags((new Seo)->hide()->hideButFollowLinks()))->apply($page, $request));
    }

    public function test_deleting_a_group_removes_its_translations(): void
    {
        $group = ProductGroup::factory()->create();
        SeoTranslations::save($group, ['de' => ['title' => 'Leer', 'description' => 'Eine leere Gruppe.']]);
        $this->translateGroupToGerman();

        $group->delete();

        $this->assertSame(['product_group:'.$this->group->id], SeoTranslation::query()->pluck('subject')->all());
    }

    private function switchOnFixtureAddon(): void
    {
        $this->seed(DefaultDataSeeder::class);
        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->saveSettings('homepage', [], true);

        // The app registered its routes before the add-on was switched on, so load them again.
        Route::middleware('web')->group(fn () => HomePageController::routes());
        Route::middleware('web')->group(fn () => app(ExtensionManager::class)->registerPublicRoutes());
        app('router')->getRoutes()->refreshNameLookups();
        app('url')->setRoutes(app('router')->getRoutes());
    }

    /**
     * The <url> block of one address in sitemap.xml.
     */
    private function sitemapEntry(string $xml, string $url): string
    {
        $this->assertSame(1, preg_match('#<url>\s*<loc>'.preg_quote(e($url), '#').'</loc>.*?</url>#s', $xml, $match), 'No sitemap entry for '.$url);

        return $match[0];
    }
}
