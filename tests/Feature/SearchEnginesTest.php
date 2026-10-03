<?php

namespace Tests\Feature;

use App\Health\SiteHealth;
use App\Health\Status;
use App\Models\Admin;
use App\Models\Announcement;
use App\Models\HealthRun;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\SeoRedirect;
use App\Seo\SeoText;
use App\Seo\ShareImage;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SearchEnginesTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/seo'));

        parent::tearDown();
    }

    public function test_a_product_page_tells_search_engines_its_title_price_and_address(): void
    {
        $product = Product::factory()->priced(899)->create(['name' => 'Business Plan']);

        $response = $this->get(route('store.product', [$product->group, $product]))->assertOk();
        $html = $response->getContent();

        $this->assertSame(1, substr_count($html, '<title>'));
        $this->assertStringContainsString('<title>Business Plan · My Hosting Company</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Business Plan: 10 GB storage, Free SSL. From $8.99/mo.">', $html);
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'/'.$product->storePath().'">', $html);
        $this->assertStringContainsString('<meta property="og:title" content="Business Plan · My Hosting Company">', $html);
        $this->assertStringContainsString('<meta property="og:type" content="product">', $html);
        $this->assertStringNotContainsString('noindex', $html);

        $data = $this->structuredData($html);
        $productData = collect($data)->firstWhere('@type', 'Product');
        $this->assertSame('Business Plan', $productData['name']);
        $this->assertSame(['@type' => 'Offer', 'price' => '8.99', 'priceCurrency' => 'USD', 'availability' => 'https://schema.org/InStock', 'url' => url('/').'/'.$product->storePath()], $productData['offers']);
        $this->assertCount(3, collect($data)->firstWhere('@type', 'BreadcrumbList')['itemListElement']);
    }

    public function test_multibyte_company_names_and_bullets_keep_titles_and_product_data_intact(): void
    {
        app(Settings::class)->setMany(['company.name' => '云科技']);
        $product = Product::factory()->priced(899)->create(['name' => 'Business Plan', 'description' => "✓ 10 GB storage\n✓ Free SSL"]);

        $html = $this->get(route('store.product', [$product->group, $product]))->assertOk()->getContent();

        $this->assertTrue(mb_check_encoding($html, 'UTF-8'));
        $this->assertStringContainsString('<title>Business Plan · 云科技</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Business Plan: ✓ 10 GB storage, ✓ Free SSL. From $8.99/mo.">', $html);
        $this->assertSame('Business Plan: ✓ 10 GB storage, ✓ Free SSL. From $8.99/mo.', collect($this->structuredData($html))->firstWhere('@type', 'Product')['description']);

        app(Settings::class)->setMany(['company.name' => 'Хостер']);
        $this->get(route('store.product', [$product->group, $product]))->assertSee('<title>Business Plan · Хостер</title>', false);
    }

    public function test_shortened_text_is_cut_by_characters_not_bytes(): void
    {
        $text = SeoText::limit(str_repeat('a', 150).' заказ без доставки и прочего', 160);

        $this->assertTrue(mb_check_encoding($text, 'UTF-8'));
        $this->assertStringEndsWith(' заказ…', $text);
        $this->assertSame(['✓ 10 GB SSD', 'استضافة مجانية', 'Free SSL™', '€5 credit'], SeoText::featureLines("- ✓ 10 GB SSD\n• استضافة مجانية\n\n * Free SSL™ \n€5 credit"));
    }

    public function test_an_array_lang_parameter_is_ignored_and_not_reported(): void
    {
        Exceptions::fake();

        $this->get('/?lang[]=1')->assertOk()->assertSee('<link rel="canonical" href="'.url('/').'/">', false);

        Exceptions::assertNothingReported();
    }

    public function test_paginated_announcement_pages_keep_their_page_in_the_canonical_address(): void
    {
        app(Settings::class)->setMany(['announcements.enabled' => true, 'locale.enabled' => ['en', 'de']]);
        Announcement::factory()->count(11)->create();
        $address = url('/').'/announcements';

        $this->get(route('announcements.index', ['page' => 2]))->assertOk()
            ->assertSee('<link rel="canonical" href="'.$address.'?page=2">', false)
            ->assertSee('<link rel="alternate" hreflang="de" href="'.$address.'?lang=de&amp;page=2">', false);
        $this->get(route('announcements.index', ['lang' => 'de', 'page' => 2]))->assertSee('<link rel="canonical" href="'.$address.'?lang=de&amp;page=2">', false);

        foreach (['page=1', 'page=abc', 'page[]=2'] as $query) {
            $this->get('/announcements?'.$query)->assertOk()->assertSee('<link rel="canonical" href="'.$address.'">', false);
        }
    }

    public function test_titles_and_descriptions_written_by_staff_replace_the_theme_ones(): void
    {
        $product = Product::factory()->priced(899)->create([
            'name' => 'Business Plan',
            'seo_title' => 'Fast SSD hosting for shops',
            'seo_description' => 'Hosting with free SSL & backups.',
        ]);

        $html = $this->get(route('store.product', [$product->group, $product]))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Fast SSD hosting for shops</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Hosting with free SSL &amp; backups.">', $html);
        $this->assertSame(1, substr_count($html, 'name="description"'));
    }

    public function test_the_home_page_uses_its_own_title_and_company_data(): void
    {
        app(Settings::class)->setMany(['seo.home_title' => 'Raz Hosting · Web hosting and domains', 'seo.home_description' => 'Fast hosting.']);
        Product::factory()->priced(500)->create();

        $html = $this->get(route('store.index'))->assertOk()->getContent();

        $this->assertStringContainsString('<title>Raz Hosting · Web hosting and domains</title>', $html);
        $this->assertStringContainsString('<meta name="description" content="Fast hosting.">', $html);
        $this->assertSame('My Hosting Company', collect($this->structuredData($html))->firstWhere('@type', 'Organization')['name']);
    }

    public function test_private_pages_are_hidden_from_search_engines(): void
    {
        $this->get(route('cart.show'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false)->assertDontSee('rel="canonical"', false);
        $this->get(route('client.login'))->assertOk()->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->get(route('store.domains', ['q' => 'example']))->assertSee('noindex, nofollow', false);
        $this->get(route('admin.login'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_a_hidden_product_is_not_shown_to_search_engines(): void
    {
        $shown = Product::factory()->priced(500)->create(['name' => 'Shown Plan']);
        $hidden = Product::factory()->priced(500)->create(['name' => 'Quiet Plan', 'seo_hidden' => true, 'product_group_id' => $shown->product_group_id]);

        $this->get(route('store.product', [$hidden->group, $hidden]))->assertOk()->assertSee('noindex, nofollow', false);
        $this->get(route('seo.sitemap'))->assertOk()
            ->assertSee(url($shown->storePath()), false)
            ->assertDontSee($hidden->storePath(), false);
    }

    public function test_the_sitemap_lists_store_pages_and_their_language_versions(): void
    {
        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de']]);
        $product = Product::factory()->priced(500)->create();
        $emptyGroup = ProductGroup::factory()->create(['slug' => 'nothing-here']);

        $xml = $this->get(route('seo.sitemap'))->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')->getContent();

        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringContainsString('<loc>'.url('/').'/</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('/').'/store/'.$product->group->slug.'</loc>', $xml);
        $this->assertStringContainsString('<loc>'.url('/').'/'.$product->storePath().'</loc>', $xml);
        $this->assertStringContainsString('hreflang="de" href="'.url('/').'/'.$product->storePath().'?lang=de"', $xml);
        $this->assertStringNotContainsString($emptyGroup->slug, $xml);

        // A new product shows up at once.
        $new = Product::factory()->priced(500)->create(['product_group_id' => $product->product_group_id]);
        $this->get(route('seo.sitemap'))->assertSee($new->storePath(), false);
    }

    public function test_robots_txt_hides_private_pages_and_points_to_the_sitemap(): void
    {
        $this->get('/robots.txt')->assertOk()
            ->assertSee('Disallow: /client')
            ->assertSee('Disallow: /checkout')
            ->assertSee('Sitemap: '.url('/').'/sitemap.xml')
            ->assertDontSee('/admin');

        app(Settings::class)->setMany(['seo.visible' => false]);

        $this->get('/robots.txt')->assertOk()->assertSee("User-agent: *\nDisallow: /\n", false)->assertDontSee('Sitemap:');
        $this->get(route('seo.sitemap'))->assertNotFound();
        $this->get(route('store.index'))->assertSee('noindex, nofollow', false);
    }

    public function test_language_versions_link_to_each_other(): void
    {
        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);
        $product = Product::factory()->priced(500)->create();
        $path = $product->storePath();

        $this->get(route('store.product', [$product->group, $product]))
            ->assertSee('<link rel="alternate" hreflang="de" href="'.url('/').'/'.$path.'?lang=de">', false)
            ->assertSee('<link rel="alternate" hreflang="x-default" href="'.url('/').'/'.$path.'">', false);

        $this->get(route('store.product', [$product->group, $product, 'lang' => 'de']))
            ->assertSee('<html lang="de"', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'/'.$path.'?lang=de">', false);

        // The choice is remembered for the next page.
        $this->get(route('store.index'))->assertSee('<html lang="de"', false);
    }

    public function test_a_changed_web_address_forwards_to_the_new_one(): void
    {
        $product = Product::factory()->priced(500)->create(['slug' => 'old-plan']);
        $group = $product->group;
        $oldGroup = $group->slug;
        $old = 'store/'.$oldGroup.'/old-plan';

        $product->update(['slug' => 'new-plan']);
        $this->get('/'.$old.'?cycle=annually')->assertRedirect(url('store/'.$group->slug.'/new-plan').'?cycle=annually')->assertStatus(301);
        $this->assertSame(1, SeoRedirect::query()->first()->hits);

        $group->update(['slug' => 'new-group']);
        $this->get('/'.$old)->assertRedirect(url('store/new-group/new-plan'));
        $this->get('/store/'.$oldGroup)->assertStatus(301);

        // Taking the old address again stops the forwarding.
        $product->update(['slug' => 'old-plan']);
        $this->assertNull(SeoRedirect::target('store/new-group/old-plan'));
    }

    public function test_the_title_pattern_applies_to_pages_without_their_own_title(): void
    {
        app(Settings::class)->setMany(['seo.title_pattern' => '{page} | {company}']);

        $this->get(route('store.domains'))->assertSee('<title>Find a domain | My Hosting Company</title>', false);
    }

    public function test_staff_set_up_search_engines_and_the_share_image(): void
    {
        $this->signInAdmin(Admin::factory()->create());

        $this->get(route('admin.settings.seo.edit'))->assertOk()->assertSee('Your home page on Google')->assertSee('Disallow: /client');

        $this->put(route('admin.settings.seo.update'), [
            'visible' => '1',
            'sitemap' => '1',
            'structured_data' => '1',
            'language_links' => '0',
            'home_title' => 'Raz Hosting',
            'title_pattern' => '{page} · {company}',
            'google_code' => '<meta name="google-site-verification" content="abc123_XYZ-9" />',
            'bing_code' => 'B1NGC0DE',
            'robots_extra' => "Disallow: /old-page\n",
            'share_image' => UploadedFile::fake()->image('share.png', 1200, 630),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('abc123_XYZ-9', setting('seo.google_code'));
        $this->assertNotNull(ShareImage::name());

        $html = $this->get(route('store.index'))->getContent();
        $this->assertStringContainsString('<meta name="google-site-verification" content="abc123_XYZ-9">', $html);
        $this->assertStringContainsString('<meta name="msvalidate.01" content="B1NGC0DE">', $html);
        $this->assertStringContainsString('<meta property="og:image" content="'.ShareImage::url().'">', $html);
        $this->assertStringContainsString('<meta property="og:image:width" content="1200">', $html);
        $this->assertStringNotContainsString('hreflang', $html);

        $this->get(route('seo.share-image', ShareImage::name()))->assertOk();
        $this->get('/robots.txt')->assertSee("Disallow: /old-page\n\nSitemap:", false);

        $this->put(route('admin.settings.seo.update'), ['title_pattern' => 'No page here'])->assertSessionHasErrors('title_pattern');
    }

    public function test_staff_write_a_search_title_and_description_for_a_product(): void
    {
        $this->signInAdmin(Admin::factory()->create());
        $product = Product::factory()->priced(899)->create(['name' => 'Business Plan']);

        $this->get(route('admin.products.edit', $product))->assertOk()
            ->assertSee('Search appearance')
            ->assertSee('Business Plan: 10 GB storage, Free SSL. From $8.99', false);

        $this->put(route('admin.products.update', $product), [
            'product_group_id' => $product->product_group_id,
            'name' => 'Business Plan',
            'slug' => $product->slug,
            'type' => 'hosting',
            'auto_setup' => 'payment',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '8.99', 'setup_fee' => '0']],
            'seo_title' => 'Business hosting with free SSL',
            'seo_description' => 'Our best plan.',
            'seo_hidden' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame('Business hosting with free SSL', $product->seo_title);
        $this->assertTrue($product->seo_hidden);
    }

    public function test_site_health_shows_how_search_engines_see_the_store_and_fixes_it(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->signInAdmin();
        app(Settings::class)->setMany(['seo.sitemap' => false]);
        $product = Product::factory()->priced(500)->create(['name' => 'Starter', 'description' => '']);
        Product::factory()->priced(900)->create(['name' => 'Bigger', 'seo_title' => 'Starter · My Hosting Company', 'product_group_id' => $product->product_group_id]);
        file_put_contents(public_path('robots.txt'), "User-agent: *\nDisallow: /private\n");

        try {
            $run = app(SiteHealth::class)->run();

            $this->assertNotNull($run->seo_score);
            $this->assertSame(Status::Warning, $run->check('seo.sitemap')->status);
            $this->assertSame(Status::Warning, $run->check('seo.robots_file')->status);
            $this->assertSame(Status::Warning, $run->check('seo.descriptions')->status);
            $this->assertSame(Status::Warning, $run->check('seo.duplicate_titles')->status);
            $this->assertSame(Status::Warning, $run->check('seo.share_image')->status);
            $this->assertSame(Status::Warning, $run->check('seo.address')->status);
            $this->assertSame(Status::Skipped, $run->check('seo.outside')->status);

            $this->get(route('admin.health.seo'))->assertOk()->assertSee('Your home page on Google')->assertSee('Titles and descriptions');
            $this->get(route('admin.health.group', ['seo', 'search-pages']))->assertOk()->assertSee(route('admin.products.edit', $product), false);

            $this->post(route('admin.health.fix'), ['check' => 'seo.sitemap'])->assertSessionHas('status');
            $this->assertTrue(setting('seo.sitemap'));

            $this->post(route('admin.health.fix'), ['check' => 'seo.robots_file'])->assertSessionHas('status');
            $this->assertFileDoesNotExist(public_path('robots.txt'));
            $this->get('/robots.txt')->assertSee('Made by Nuvabill');
            $this->assertSame(Status::Passed, HealthRun::latestRun()->check('seo.robots_file')->status);
        } finally {
            @unlink(public_path('robots.txt'));
            File::deleteDirectory(storage_path('app/quarantine'));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function structuredData(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

        return array_map(fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR), $matches[1]);
    }
}
