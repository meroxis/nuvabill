<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TldPrice;
use App\Seo\SeoTranslations;
use App\Support\Settings;
use App\Support\SiteLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Tests\TestCase;

/**
 * Language menu links search engines can follow, addresses with a language the site does not
 * offer, choosing a language on a ?lang= page, and the footer's store links (0.7.4).
 */
class LanguageLinksTest extends TestCase
{
    use RefreshDatabase;

    private ProductGroup $group;

    protected function setUp(): void
    {
        parent::setUp();

        app(Settings::class)->setMany(['locale.enabled' => ['en', 'de', 'ar']]);
        $this->group = Product::factory()->priced(500)->create()->group;
    }

    public function test_the_language_menu_has_plain_links_search_engines_can_follow(): void
    {
        SeoTranslations::save(SeoTranslations::HOME, ['de' => ['title' => 'Webhosting für alle', 'description' => 'Schnelles Webhosting mit kostenlosem SSL.']]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(url('/'), '#').'/\?lang=de" hreflang="de"[^>]*class="lang-menu-item"#', $html);
        $this->assertMatchesRegularExpression('#<a href="'.preg_quote(url('/'), '#').'/" hreflang="en"#', $html);
        // A click still goes through the form, so a signed-in client's choice is saved.
        $this->assertStringContainsString('@click.prevent="choose(\'de\')"', $html);
        // Arabic is not translated yet: it stays a button, not a link.
        $this->assertStringContainsString('name="locale" value="ar"', $html);
        $this->assertStringNotContainsString('?lang=ar"', $html);
    }

    public function test_private_pages_get_no_language_links(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertStringContainsString('name="locale" value="de"', $html);
        $this->assertStringNotContainsString('?lang=', $html);
        $this->assertStringNotContainsString('hreflang=', $html);
    }

    public function test_an_unknown_or_switched_off_language_redirects_to_the_clean_address(): void
    {
        $this->get('/?lang=da')->assertStatus(301)->assertRedirect(url('/'))->assertHeaderMissing('Set-Cookie');

        // Thai is a language Nuvabill speaks, but this site has it switched off.
        $this->get('/store/'.$this->group->slug.'?lang=th&cycle=annually')->assertStatus(301)
            ->assertRedirect(url('/store/'.$this->group->slug.'?cycle=annually'))
            ->assertHeaderMissing('Set-Cookie');

        $this->get('/?lang[]=x')->assertStatus(301)->assertRedirect(url('/'));

        // Languages the site offers stay where they are, the default one too.
        $this->get('/?lang=de')->assertOk();
        $this->get('/?lang=en')->assertOk();
    }

    public function test_choosing_a_language_leaves_the_language_address(): void
    {
        $page = url('/store/'.$this->group->slug);

        $this->get($page.'?lang=de')->assertOk()->assertSee('lang="de"', false);

        $this->from($page.'?lang=de')->post(route('language.update'), ['locale' => 'ar'])->assertRedirect($page);

        $this->get($page)->assertOk()->assertSee('<html lang="ar"', false);
    }

    public function test_other_parts_of_the_address_are_kept_when_choosing_a_language(): void
    {
        $page = url('/store/'.$this->group->slug);

        $this->from($page.'?cycle=annually&lang=de')->post(route('language.update'), ['locale' => 'ar'])
            ->assertRedirect($page.'?cycle=annually');
    }

    public function test_a_foreign_previous_address_is_never_used(): void
    {
        $response = $this->from('https://evil.example/x?lang=de')->post(route('language.update'), ['locale' => 'de']);

        $response->assertRedirect(route('store.index'));
        $this->assertStringNotContainsString('evil.example', (string) $response->headers->get('Location'));
    }

    public function test_the_footer_links_to_every_store_group_and_domain_search(): void
    {
        $second = Product::factory()->priced(900)->create()->group;
        $hidden = Product::factory()->priced(900)->create()->group;
        $hidden->update(['seo_hidden' => true]);
        $empty = ProductGroup::factory()->create();
        TldPrice::query()->create(['tld' => 'com', 'currency' => 'USD', 'register_price' => 1200, 'transfer_price' => 1200, 'renew_price' => 1200, 'is_enabled' => true]);

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertSame(1, preg_match('#<nav class="footer-nav" aria-label="Our services">(.*?)</nav>#s', $html, $nav));

        $this->assertStringContainsString('<a href="'.route('store.group', $this->group).'">', $nav[1]);
        $this->assertStringContainsString('<a href="'.route('store.group', $second).'">', $nav[1]);
        $this->assertStringContainsString('<a href="'.route('store.domains').'"><bdi>Domains</bdi></a>', $nav[1]);
        $this->assertStringNotContainsString(route('store.group', $hidden), $nav[1]);
        $this->assertStringNotContainsString(route('store.group', $empty), $nav[1]);
    }

    public function test_the_footer_has_no_store_links_on_the_marketplace_store(): void
    {
        config(['nuvabill.marketplace.store' => true]);

        $this->assertSame([], SiteLinks::store());
    }

    public function test_a_broken_language_address_is_not_reported(): void
    {
        Exceptions::fake();

        $this->get('/store/'.$this->group->slug.'?lang[a][b]=1&x=1')->assertStatus(301)
            ->assertRedirect(url('/store/'.$this->group->slug.'?x=1'));

        Exceptions::assertNothingReported();
    }
}
