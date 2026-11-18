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
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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
            // The language menu still links the ready versions (0.7.4), but the head has no language links.
            ->assertDontSee('<link rel="alternate" hreflang=', false);
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

    public function test_the_sitemap_reads_no_help_texts_but_still_knows_which_languages_are_ready(): void
    {
        $article = KbArticle::factory()->create();
        $article->saveTranslation('de', 'Wie ändere ich das?', 'Öffnen Sie den Kundenbereich.');
        // A text of only spaces and line breaks is no text.
        $article->saveTranslation('ar', 'كيف أغير ذلك؟', " \n\t\r ");
        $other = KbArticle::factory()->create(['kb_category_id' => $article->kb_category_id]);
        $other->saveTranslation('ar', 'سؤال آخر', "\n<p>جواب</p>\n");
        $article->category->saveTranslation('de', 'Hilfe', null);
        $news = Announcement::factory()->create();
        $news->saveTranslation('ar', 'خبر', 'نص الخبر.');
        $news->saveTranslation('de', 'Neuigkeit', '');
        // A language that is switched off is never offered.
        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar', 'fr']]);
        $article->saveTranslation('fr', 'Comment changer cela ?', 'Ouvrez votre espace client.');
        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);

        // What each page says itself, read one by one with the whole texts.
        $expected = [
            'knowledgebase/'.$article->category->slug.'/'.$article->slug => $article->fresh()->readyLocales(),
            'knowledgebase/'.$article->category->slug.'/'.$other->slug => $other->fresh()->readyLocales(),
            'knowledgebase/'.$article->category->slug => $article->category->fresh()->readyLocales(false),
            'announcements/'.$news->slug => $news->fresh()->readyLocales(),
        ];
        $this->assertSame(['de'], $expected['knowledgebase/'.$article->category->slug.'/'.$article->slug]);
        $this->assertSame(['ar'], $expected['knowledgebase/'.$article->category->slug.'/'.$other->slug]);
        $this->assertSame(['de'], $expected['knowledgebase/'.$article->category->slug]);
        $this->assertSame(['ar'], $expected['announcements/'.$news->slug]);

        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            // MySQL and MariaDB quote names with ` instead of ".
            $queries[] = str_replace('`', '"', $query->sql);
        });
        $pages = collect(app(Sitemap::class)->pages())->keyBy('path');

        foreach ($expected as $path => $locales) {
            $this->assertSame($locales, $pages[$path]['languages'] ?? null, $path);
        }

        $help = array_values(array_filter($queries, fn (string $sql): bool => str_contains($sql, 'content_translations') || str_contains($sql, 'from "kb_articles"') || str_contains($sql, 'from "announcements"')));
        $this->assertNotEmpty($help);

        foreach ($help as $sql) {
            // Neither the translated texts nor the articles' own texts are read.
            $this->assertDoesNotMatchRegularExpression('/^\s*select \*|"body"|"(content_translations|kb_articles|announcements)"\.\*/i', $sql, $sql);
        }
    }

    public function test_a_group_whose_plans_are_all_kept_out_of_search_results_stays_in_the_sitemap(): void
    {
        $product = $this->group->products()->firstOrFail();
        $product->update(['seo_hidden' => true]);
        Sitemap::forget();

        // The group page is still a page search engines may show, and the plan points to it.
        $this->get('/store/'.$this->group->slug)->assertOk()->assertDontSee('noindex', false);
        $xml = app(Sitemap::class)->xml();
        $this->sitemapEntry($xml, $this->groupUrl());
        $this->assertStringNotContainsString('/'.$product->slug.'<', $xml);

        // With plan pages of their own, the hidden plan still gets no entry, but its group does.
        app(Settings::class)->setMany(['seo.product_pages' => true]);
        Sitemap::forget();
        $xml = app(Sitemap::class)->xml();
        $this->sitemapEntry($xml, $this->groupUrl());
        $this->assertStringNotContainsString('/'.$product->slug.'<', $xml);

        // A plan visitors cannot order at all still leaves its group out.
        $product->update(['is_visible' => false]);
        Sitemap::forget();
        $this->assertStringNotContainsString('store/'.$this->group->slug.'<', app(Sitemap::class)->xml());
    }

    public function test_search_texts_are_read_once_per_request(): void
    {
        $this->translateGroupToGerman();
        $subject = SeoTranslations::subject($this->group);
        $reads = 0;
        DB::listen(function (QueryExecuted $query) use (&$reads, $subject): void {
            // The reads of the texts, not the look-up save() does before it writes.
            if (str_contains(str_replace('`', '"', $query->sql), 'from "seo_translations" where "subject" in') && in_array($subject, $query->bindings, true)) {
                $reads++;
            }
        });

        SeoTranslations::all($this->group);
        SeoTranslations::title($this->group, 'de');
        SeoTranslations::description($this->group, 'de');
        SeoTranslations::readyLocales($this->group, 'Fast web hosting', 'Web hosting with free SSL and daily backups.');
        $this->assertSame(1, $reads);

        // Saving forgets what was read.
        SeoTranslations::save($this->group, ['de' => ['title' => 'Webhosting', 'description' => 'Neue Beschreibung.']]);
        $this->assertSame('Webhosting', SeoTranslations::title($this->group, 'de'));
        $this->assertSame(2, $reads);
        SeoTranslations::forget($this->group);
        $this->assertSame([], SeoTranslations::all($this->group));
        $this->assertSame(3, $reads);

        // A store page reads its texts once, and the next request reads them again.
        SeoTranslations::save($this->group, ['de' => ['title' => 'Schnelles Webhosting', 'description' => 'Webhosting mit kostenlosem SSL.']]);
        $reads = 0;
        $this->get('/store/'.$this->group->slug.'?lang=de')->assertOk()->assertSee('<title>Schnelles Webhosting</title>', false);
        $this->assertSame(1, $reads);

        SeoTranslation::query()->where('subject', $subject)->update(['title' => 'Webhosting für alle']);
        $this->get('/store/'.$this->group->slug.'?lang=de')->assertOk()->assertSee('<title>Webhosting für alle</title>', false);
        $this->assertSame(2, $reads);
    }

    public function test_a_page_can_offer_no_language_versions(): void
    {
        $this->translateGroupToGerman();
        SeoTranslations::save(SeoTranslations::HOME, ['de' => ['title' => 'Webhosting für alle', 'description' => 'Schnelles Webhosting mit kostenlosem SSL.']]);

        // Without it, the page links its German version.
        $this->get('/')->assertOk()->assertSee('hreflang="de"', false);

        // A page whose main address is on another site, such as a website page.
        View::composer('theme::layouts.*', fn () => app(Seo::class)->withoutLanguageVersions());

        foreach ([true, false] as $readyOnly) {
            app(Settings::class)->setMany(['seo.ready_languages_only' => $readyOnly]);
            app()->forgetInstance(Seo::class);

            $html = $this->get('/')->assertOk()->getContent();
            $this->assertStringNotContainsString('hreflang=', $html);
            $this->assertStringNotContainsString('?lang=', $html);
            // Visitors can still pick a language.
            $this->assertStringContainsString('name="locale" value="de"', $html);
        }

        $page = '<html><head><title>x</title></head><body></body></html>';
        $request = Request::create('/store/'.$this->group->slug);
        $seo = (new Seo)->allowIndexing();
        $this->assertStringContainsString('hreflang="x-default"', (new HeadTags($seo))->apply($page, $request));
        $seo->withoutLanguageVersions();
        $this->assertStringNotContainsString('hreflang=', (new HeadTags($seo))->apply($page, $request));
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

    public function test_staff_write_the_home_page_search_text_in_other_languages(): void
    {
        $this->signInAdmin();
        $settings = ['visible' => '1', 'sitemap' => '1', 'structured_data' => '1', 'language_links' => '1', 'ready_languages_only' => '1',
            'home_title' => 'Raz Hosting', 'home_description' => 'Web hosting, domains and servers for small companies.'];

        $this->put(route('admin.settings.seo.update'), $settings + [
            'seo_translations' => ['de' => ['title' => '  Raz Hosting  ', 'description' => 'Webhosting, Domains und Server für kleine Firmen.'], 'ar' => ['title' => '', 'description' => '']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([['de', 'Raz Hosting', 'Webhosting, Domains und Server für kleine Firmen.']],
            SeoTranslation::query()->where('subject', SeoTranslations::HOME)->get()->map(fn (SeoTranslation $row): array => [$row->locale, $row->title, $row->description])->all());

        $this->get('/?lang=de')->assertOk()
            ->assertSee('<meta name="description" content="Webhosting, Domains und Server für kleine Firmen.">', false)
            ->assertSee('hreflang="de"', false)
            ->assertDontSee('hreflang="ar"', false);
        $this->get('/?lang=en')->assertOk()->assertSee('<meta name="description" content="Web hosting, domains and servers for small companies.">', false);

        // Both fields emptied: the language goes back to the main text.
        $this->put(route('admin.settings.seo.update'), $settings + ['seo_translations' => ['de' => ['title' => '', 'description' => '']]])->assertSessionHasNoErrors();

        $this->assertSame(0, SeoTranslation::query()->count());
        $this->get('/?lang=de')->assertOk()->assertSee('<meta name="description" content="Web hosting, domains and servers for small companies.">', false);
    }

    public function test_staff_write_group_and_product_search_text_in_other_languages(): void
    {
        $this->signInAdmin();

        $this->post(route('admin.product-groups.store'), [
            'name' => 'VPS', 'slug' => 'vps', 'is_visible' => '1',
            'seo_translations' => ['de' => ['title' => 'Virtuelle Server', 'description' => 'Schnelle virtuelle Server mit Root-Zugang.']],
        ])->assertRedirect(route('admin.products.index'))->assertSessionHasNoErrors();

        $vps = ProductGroup::query()->where('slug', 'vps')->sole();
        $this->assertSame('Virtuelle Server', SeoTranslations::title($vps, 'de'));

        $this->put(route('admin.product-groups.update', $this->group), [
            'name' => $this->group->name, 'slug' => $this->group->slug, 'is_visible' => '1',
            'seo_title' => 'Fast web hosting', 'seo_description' => 'Web hosting with free SSL and daily backups.',
            'seo_translations' => ['ar' => ['title' => 'استضافة سريعة', 'description' => 'استضافة مواقع مع SSL مجاني ونسخ احتياطي يومي.']],
        ])->assertRedirect(route('admin.products.index'))->assertSessionHasNoErrors();

        $this->get('/store/'.$this->group->slug.'?lang=ar')->assertOk()->assertSee('<title>استضافة سريعة</title>', false);

        $product = $this->group->products()->firstOrFail();
        $this->put(route('admin.products.update', $product), [
            'product_group_id' => $product->product_group_id,
            'name' => $product->name,
            'slug' => $product->slug,
            'type' => 'hosting',
            'auto_setup' => 'payment',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '5.00', 'setup_fee' => '0']],
            'seo_translations' => ['de' => ['title' => 'Starter-Paket', 'description' => 'Das kleine Paket für den Anfang.']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['de' => ['title' => 'Starter-Paket', 'description' => 'Das kleine Paket für den Anfang.']], SeoTranslations::all($product));
        $this->assertNull($product->fresh()->getAttribute('seo_translations'));
    }

    public function test_search_text_for_a_language_that_is_off_is_refused(): void
    {
        $this->signInAdmin();

        // th is a Nuvabill language but is not switched on here; xx is no language at all.
        foreach (['th', 'xx'] as $code) {
            $this->put(route('admin.product-groups.update', $this->group), [
                'name' => $this->group->name, 'slug' => $this->group->slug,
                'seo_translations' => [$code => ['title' => 'Hosting', 'description' => 'Hosting text.']],
            ])->assertSessionHasErrors('seo_translations');
        }

        $this->put(route('admin.settings.seo.update'), ['seo_translations' => ['de' => ['title' => str_repeat('a', 121)]]])
            ->assertSessionHasErrors('seo_translations.de.title');
        $this->put(route('admin.settings.seo.update'), ['seo_translations' => ['de' => ['title' => 'Hallo', 'colour' => 'red']]])
            ->assertSessionHasErrors('seo_translations.de');

        $this->assertSame(0, SeoTranslation::query()->count());
    }

    public function test_the_admin_form_shows_which_languages_are_ready(): void
    {
        $this->signInAdmin();
        $this->translateGroupToGerman();
        SeoTranslations::save($this->group, ['ar' => ['title' => 'استضافة سريعة', 'description' => '']]);

        $this->get(route('admin.product-groups.edit', $this->group))->assertOk()
            ->assertSeeInOrder(['Other languages', '1 of 2 ready', 'German', 'Ready', 'Schnelles Webhosting', 'Arabic', 'Not offered to search engines', 'استضافة سريعة'])
            ->assertSee('name="seo_translations[ar][description]"', false)
            ->assertSee('lang="ar" dir="rtl"', false)
            // Each language's two fields are named by that language for screen readers.
            ->assertSee('role="group" aria-labelledby="f-seo-de-name"', false)
            ->assertSee('<strong id="f-seo-de-name">German</strong>', false)
            ->assertSee('role="group" aria-labelledby="f-seo-ar-name"', false);

        $this->get(route('admin.products.create'))->assertOk()->assertSee('0 of 2 ready');
        $this->get(route('admin.settings.seo.edit'))->assertOk()->assertSee('name="seo_translations[de][title]"', false);

        // With one language there is nothing to translate.
        app(Settings::class)->setMany(['locale.enabled' => ['en']]);
        $this->get(route('admin.product-groups.edit', $this->group))->assertOk()->assertDontSee('Other languages');
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
