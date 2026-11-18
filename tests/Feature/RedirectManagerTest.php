<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SeoMissingPage;
use App\Models\SeoRedirect;
use App\Seo\RobotsTxt;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Settings → Search engines → Redirects: staff's own redirects, the old WHMCS addresses, the
 * robots.txt lines that let search engines see them, and addresses people could not open (0.7.4).
 */
class RedirectManagerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $data
     */
    private function addRedirect(array $data): TestResponse
    {
        return $this->from(route('admin.settings.seo.redirects.index'))
            ->post(route('admin.settings.seo.redirects.store'), [...['status' => 301, 'keep_query' => 0], ...$data]);
    }

    public function test_staff_add_a_redirect_and_visitors_are_sent_there(): void
    {
        $this->signInAdmin();

        $this->addRedirect(['from' => '/old-page', 'to' => '/knowledgebase'])
            ->assertRedirect(route('admin.settings.seo.redirects.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Redirect saved.');
        $this->addRedirect(['from' => 'summer-offer', 'to' => 'store?cycle=annually', 'status' => 302, 'keep_query' => 1])->assertSessionHasNoErrors();

        // Moved for good, and the part after ? is left behind.
        $this->get('/old-page?utm_source=mail')->assertStatus(301)->assertRedirect(url('knowledgebase'));
        // Moved for now, and the part after ? goes along.
        $this->get('/summer-offer?ref=7')->assertStatus(302)->assertRedirect(url('store').'?cycle=annually&ref=7');

        $redirect = SeoRedirect::query()->where('from_path', 'old-page')->firstOrFail();
        $this->assertSame(1, $redirect->hits);
        $this->assertNotNull($redirect->last_hit_at);
        $this->assertSame('manual', $redirect->source);
        $this->assertFalse($redirect->keep_query);

        // Staff change it: now moved for now, to another page.
        $this->from(route('admin.settings.seo.redirects.index'))
            ->put(route('admin.settings.seo.redirects.update', $redirect), ['from' => '/old-page', 'to' => '/announcements', 'status' => 302, 'keep_query' => 0])
            ->assertSessionHasNoErrors();
        $this->get('/old-page')->assertStatus(302)->assertRedirect(url('announcements'));
        $this->assertSame(2, $redirect->fresh()->hits);
    }

    public function test_a_redirect_never_leaves_the_site(): void
    {
        $this->signInAdmin();

        foreach (['https://evil.example/x', '//evil.example', '/\\evil.example', 'javascript:alert(1)', '/a b', 'https://user@'.parse_url(url('/'), PHP_URL_HOST).'/x', 'evil.example@x', "/x\ty"] as $target) {
            $this->addRedirect(['from' => 'old-page', 'to' => $target])->assertSessionHasErrors('to');
        }

        foreach (['https://evil.example/old', '//evil.example/old'] as $from) {
            $this->addRedirect(['from' => $from, 'to' => '/login'])->assertSessionHasErrors('from');
        }

        $this->assertSame(0, SeoRedirect::query()->count());
        $this->assertNull(SeoRedirect::cleanTarget('mailto:raz@example.com'));
        $this->assertNull(SeoRedirect::cleanTarget('data:text/html,hi'));
    }

    public function test_a_full_address_on_this_site_is_kept_as_a_path(): void
    {
        $this->signInAdmin();

        $this->addRedirect(['from' => url('/old-plans'), 'to' => url('/knowledgebase').'?q=vps#top'])->assertSessionHasNoErrors();

        $redirect = SeoRedirect::query()->sole();
        $this->assertSame('old-plans', $redirect->from_path);
        $this->assertSame('knowledgebase?q=vps', $redirect->to_path);
        $this->assertSame('login', SeoRedirect::cleanTarget('login'));
        $this->assertSame('', SeoRedirect::cleanTarget('/'));
    }

    public function test_loops_are_refused_and_chains_go_straight_to_the_end(): void
    {
        $this->signInAdmin();
        $this->addRedirect(['from' => 'a', 'to' => '/b'])->assertSessionHasNoErrors();

        $this->addRedirect(['from' => 'b', 'to' => '/a'])->assertSessionHasErrors(['to' => 'This would send visitors in a circle.']);
        $this->addRedirect(['from' => 'x', 'to' => '/X?page=2'])->assertSessionHasErrors(['to' => 'The address would send visitors to itself.']);
        $this->addRedirect(['from' => 'a', 'to' => '/d'])->assertSessionHasErrors(['from' => 'Another redirect already uses this old address.']);

        $this->addRedirect(['from' => 'c', 'to' => '/a'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Redirect saved. It now goes straight to /b.');
        $this->assertSame('b', SeoRedirect::query()->where('from_path', 'c')->value('to_path'));

        // Addresses that forwarded to the new old address now go straight to the end.
        $this->addRedirect(['from' => 'b', 'to' => '/e'])->assertSessionHasNoErrors();
        $this->assertSame(['a' => 'e', 'b' => 'e', 'c' => 'e'], SeoRedirect::query()->orderBy('from_path')->pluck('to_path', 'from_path')->all());
    }

    public function test_reserved_addresses_cannot_be_redirected(): void
    {
        $this->signInAdmin();

        foreach (['/'.config('nuvabill.admin_path').'/clients', 'build/x', 'sitemap.xml', '/', 'api/v1/clients', 'Robots.txt'] as $from) {
            $this->addRedirect(['from' => $from, 'to' => '/login'])->assertSessionHasErrors(['from' => 'Nuvabill uses this address.']);
        }

        $this->addRedirect(['from' => 'clientarea.php?action=services', 'to' => '/login'])->assertSessionHasErrors(['from' => 'Leave out the part after ?']);
        $this->assertSame(0, SeoRedirect::query()->count());
    }

    public function test_the_whmcs_pack_forwards_old_addresses(): void
    {
        $this->signInAdmin();
        // A redirect staff made before is kept.
        SeoRedirect::saveManual('contact.php', '/knowledgebase', 301, false);

        $this->from(route('admin.settings.seo.redirects.index'))->post(route('admin.settings.seo.redirects.whmcs'))->assertSessionHas('status');
        $count = SeoRedirect::query()->count();

        $this->get('/clientarea.php?action=services')->assertStatus(301)->assertRedirect(url('login'));
        $this->get('/cart.php?a=view')->assertStatus(301)->assertRedirect(url('/'));
        $this->get('/password/reset')->assertStatus(301)->assertRedirect(url('forgot-password'));
        $this->get('/contact.php')->assertRedirect(url('knowledgebase'));
        // Affiliates are off, so their old page is not added.
        $this->assertNull(SeoRedirect::target('affiliates.php'));
        $this->assertSame('whmcs', SeoRedirect::target('clientarea.php')->source);
        $this->assertTrue((bool) setting('seo.whmcs_addresses'));

        // A second click adds nothing.
        $this->from(route('admin.settings.seo.redirects.index'))->post(route('admin.settings.seo.redirects.whmcs'))
            ->assertSessionHas('status', 'The old WHMCS addresses forward already.');
        $this->assertSame($count, SeoRedirect::query()->count());
    }

    public function test_old_friendly_addresses_with_rp_are_forwarded(): void
    {
        $this->get('/?rp=/knowledgebase/1/x')->assertOk();

        app(Settings::class)->setMany(['seo.whmcs_addresses' => true]);

        $this->get('/?rp=/knowledgebase/1/x&foo=bar')->assertStatus(301)->assertRedirect(url('knowledgebase'))->assertHeaderMissing('Set-Cookie');
        $this->get('/index.php?rp=/announcements/4/news')->assertStatus(301)->assertRedirect(url('announcements'));
        $this->get('/index.php?rp=/store/web-hosting')->assertStatus(301)->assertRedirect(url('store/web-hosting'));
        $this->get('/?rp=/login')->assertRedirect(url('login'));

        foreach (['//evil.example', '/\\evil.example', 'https://evil.example', '/%0d%0aLocation:x'] as $rp) {
            $location = $this->get('/?rp='.rawurlencode($rp))->assertStatus(301)->headers->get('Location');
            $this->assertSame(parse_url(url('/'), PHP_URL_HOST), parse_url((string) $location, PHP_URL_HOST), $rp);
        }
    }

    public function test_robots_txt_lets_search_engines_see_redirected_private_paths(): void
    {
        $this->signInAdmin();
        $this->post(route('admin.settings.seo.redirects.whmcs'));

        $robots = $this->get('/robots.txt')->assertOk()->getContent();

        $this->assertStringContainsString("Allow: /cart.php\n", $robots);
        $this->assertStringContainsString("Allow: /clientarea.php\n", $robots);
        $this->assertStringNotContainsString('Allow: /login.php', $robots);
        $this->assertLessThan(strpos($robots, 'Disallow: /client'), strpos($robots, 'Allow: /clientarea.php'));
        $this->assertLessThan(strpos($robots, 'Disallow: /cart'), strpos($robots, 'Allow: /cart.php'));
    }

    public function test_staff_see_when_their_own_robots_line_blocks_a_redirect(): void
    {
        $this->signInAdmin();
        app(Settings::class)->setMany(['seo.robots_extra' => "Disallow: /old\n\nUser-agent: Bingbot\nDisallow: /other"]);
        SeoRedirect::saveManual('old-page', '/knowledgebase', 301, false);

        $this->get(route('admin.settings.seo.redirects.index'))->assertOk()
            ->assertSee('robots.txt blocks it')
            ->assertSee('Disallow: /old');

        $this->assertSame('Disallow: /old', RobotsTxt::blockedByOwnLine('old-page'));
        // A line for another search engine only does not count.
        $this->assertNull(RobotsTxt::blockedByOwnLine('other-page'));

        app(Settings::class)->setMany(['seo.robots_extra' => "Disallow: /*.php$\nAllow: /old-page"]);
        $this->assertNull(RobotsTxt::blockedByOwnLine('old-page'));
        $this->assertSame('Disallow: /*.php$', RobotsTxt::blockedByOwnLine('cart.php'));
        $this->assertNull(RobotsTxt::blockedByOwnLine('cart.php5'));
    }

    public function test_missing_pages_are_counted_without_personal_data(): void
    {
        $this->get('/no-such-page', ['Referer' => 'https://elsewhere.example/', 'User-Agent' => 'Raz browser'])->assertNotFound();
        $this->get('/no-such-page?x=1')->assertNotFound();
        $this->get('/theme.css')->assertNotFound();
        $this->get('/'.config('nuvabill.admin_path').'/no-such-page');
        $this->post('/no-such-form')->assertStatus(404);

        $page = SeoMissingPage::query()->sole();
        $this->assertSame('no-such-page', $page->path);
        $this->assertSame(2, $page->hits);
        $this->assertEqualsCanonicalizing(['id', 'path', 'hits', 'first_seen_at', 'last_seen_at'], array_keys($page->getAttributes()));

        $this->signInAdmin();
        $this->get(route('admin.settings.seo.redirects.index'))->assertOk()->assertSee('/no-such-page');
        $this->get(route('admin.settings.seo.redirects.index', ['from' => 'no-such-page']))->assertSee('value="no-such-page"', false);

        // Making a redirect takes the address off the list.
        $this->addRedirect(['from' => 'no-such-page', 'to' => '/'])->assertSessionHasNoErrors();
        $this->assertSame(0, SeoMissingPage::query()->count());

        $this->get('/gone-a');
        $this->get('/gone-b');
        $this->delete(route('admin.settings.seo.missing.destroy', SeoMissingPage::query()->where('path', 'gone-a')->sole()))->assertSessionHas('status');
        $this->assertSame(['gone-b'], SeoMissingPage::query()->pluck('path')->all());
        $this->delete(route('admin.settings.seo.missing.clear'))->assertSessionHas('status', 'The list is empty now.');
        $this->assertSame(0, SeoMissingPage::query()->count());
    }

    public function test_redirect_pages_need_the_settings_permission(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create());
        $redirect = SeoRedirect::query()->create(['from_path' => 'a', 'to_path' => 'b']);
        $missing = SeoMissingPage::query()->create(['path' => 'gone', 'hits' => 1]);

        $this->get(route('admin.settings.seo.redirects.index'))->assertForbidden();
        $this->addRedirect(['from' => 'c', 'to' => '/d'])->assertForbidden();
        $this->put(route('admin.settings.seo.redirects.update', $redirect), ['from' => 'a', 'to' => '/e', 'status' => 301])->assertForbidden();
        $this->post(route('admin.settings.seo.redirects.whmcs'))->assertForbidden();
        $this->delete(route('admin.settings.seo.missing.destroy', $missing))->assertForbidden();
        $this->delete(route('admin.settings.seo.missing.clear'))->assertForbidden();

        $this->assertSame(1, SeoRedirect::query()->count());
        $this->assertSame(1, SeoMissingPage::query()->count());
    }

    public function test_the_demo_cannot_change_redirects(): void
    {
        config(['nuvabill.demo' => true]);
        $this->signInAdmin();
        $missing = SeoMissingPage::query()->create(['path' => 'gone', 'hits' => 1]);

        $this->get(route('admin.settings.seo.redirects.index'))->assertOk();
        $this->addRedirect(['from' => 'c', 'to' => '/d'])->assertSessionHas('error');
        $this->post(route('admin.settings.seo.redirects.whmcs'))->assertSessionHas('error');
        $this->delete(route('admin.settings.seo.missing.destroy', $missing))->assertSessionHas('error');
        $this->delete(route('admin.settings.seo.missing.clear'))->assertSessionHas('error');

        $this->assertSame(0, SeoRedirect::query()->count());
        $this->assertSame(1, SeoMissingPage::query()->count());
        $this->assertFalse((bool) setting('seo.whmcs_addresses'));
    }
}
