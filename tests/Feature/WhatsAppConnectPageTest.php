<?php

namespace Tests\Feature;

use App\Chat\WhatsAppConnectLink;
use App\Models\MarketplaceSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The WhatsApp connect page hands the owner's access token to the site that opened it, so it
 * only opens for a site it knows: its own site, or a site connected to the marketplace.
 */
class WhatsAppConnectPageTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'https://my.nuvabill.com';

    private const SITE = 'billing.example.com';

    private const KEY = 'nvb_site_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const STATE = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'nuvabill.whatsapp_connect.url' => self::STORE.'/connect/whatsapp',
            'nuvabill.whatsapp_connect.app_id' => '111',
            'nuvabill.whatsapp_connect.app_secret' => 'shh',
            'nuvabill.whatsapp_connect.config_id' => '222',
            'nuvabill.whatsapp_connect.open' => true,
            'nuvabill.marketplace.url' => self::STORE,
        ]);
    }

    public function test_the_page_opens_for_a_connected_site_that_signed_the_link(): void
    {
        $site = $this->storeSite();
        $link = $this->linkFrom(self::SITE, $site->id);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);

        $this->assertSame(['origin', 'state', 'site', 'time', 'signature'], array_keys($query));
        $this->assertSame('https://'.self::SITE, $query['origin']);

        $page = $this->get($link)->assertOk()
            ->assertSee('connect.facebook.net')
            ->assertSee('Only continue if https://billing.example.com is your own Nuvabill site')
            ->assertSee($query['signature']);
        $this->assertStringContainsString('https://connect.facebook.net', (string) $page->headers->get('Content-Security-Policy'));
        $this->assertSame('unsafe-none', $page->headers->get('Cross-Origin-Opener-Policy'));

        // The key the site had before its last change still signs for a day.
        $site->forceFill(['key_hash' => MarketplaceSite::hashKey('nvb_site_'.str_repeat('n', 43)), 'previous_key_hash' => MarketplaceSite::hashKey(self::KEY), 'previous_key_expires_at' => now()->addHours(2)])->save();
        $this->assertOpens($this->get($link));
        $site->forceFill(['previous_key_expires_at' => now()->subMinute()])->save();
        $this->assertRefused($this->get($link));
    }

    public function test_the_page_stays_closed_for_a_link_it_cannot_trust(): void
    {
        $site = $this->storeSite();
        $link = $this->linkFrom(self::SITE, $site->id);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $with = fn (array $changes): string => self::STORE.'/connect/whatsapp?'.http_build_query($changes + $query);

        $this->assertOpens($this->get($link));

        // Another address, state, site or time than the one that was signed.
        $this->assertRefused($this->get($with(['origin' => 'https://lookalike.example.net'])));
        $this->assertRefused($this->get($with(['origin' => 'https://billing.example.com.lookalike.example.net'])));
        $this->assertRefused($this->get($with(['origin' => 'https://billing.example.com:8443'])));
        $this->assertRefused($this->get($with(['state' => str_repeat('b', 32)])));
        $this->assertRefused($this->get($with(['site' => $site->id + 1])));
        $this->assertRefused($this->get($with(['time' => $query['time'] + 60])));
        $this->assertRefused($this->get($with(['signature' => str_repeat('0', 64)])));
        $this->assertRefused($this->get($with(['signature' => ['x']])));
        $this->assertRefused($this->get($with(['origin' => 'javascript:alert(1)'])));

        // A connected site that signs for an address it did not connect with.
        $this->assertRefused($this->get($this->linkFrom('other.example.net', $site->id)));

        // A site the store does not know, or no longer knows.
        $this->assertRefused($this->get($this->linkFrom(self::SITE, $site->id + 1)));
        $site->forceFill(['status' => MarketplaceSite::STATUS_REVOKED])->save();
        $this->assertRefused($this->get($link));
        $site->forceFill(['status' => MarketplaceSite::STATUS_ACTIVE])->save();
        $this->assertOpens($this->get($link));

        // A site staff removed before, that connected again and waits for staff.
        $site->forceFill(['status' => MarketplaceSite::STATUS_PENDING, 'pending_reason' => MarketplaceSite::REASON_REVOKED])->save();
        $this->assertRefused($this->get($link));
        $site->forceFill(['pending_reason' => MarketplaceSite::REASON_NOT_REACHED])->save();
        $this->assertOpens($this->get($link));
        $site->forceFill(['status' => MarketplaceSite::STATUS_ACTIVE, 'pending_reason' => null])->save();

        // Until the page is opened to everyone, it is only for its own site.
        config(['nuvabill.whatsapp_connect.open' => false]);
        $this->assertRefused($this->get($link));
        config(['nuvabill.whatsapp_connect.open' => true]);

        // A link is good for an hour.
        $this->travel(WhatsAppConnectLink::MINUTES + 1)->minutes();
        $this->assertRefused($this->get($link));
    }

    public function test_a_link_without_a_signature_only_opens_the_page_for_known_sites_while_that_is_allowed(): void
    {
        $unsigned = fn (string $origin): string => self::STORE.'/connect/whatsapp?'.http_build_query(['origin' => $origin, 'state' => self::STATE]);

        $this->assertRefused($this->get($unsigned('https://'.self::SITE)));

        // A connected site on an older Nuvabill.
        $site = $this->storeSite();
        $this->assertOpens($this->get($unsigned('https://'.self::SITE.'/')));
        $site->forceFill(['status' => MarketplaceSite::STATUS_PENDING, 'pending_reason' => MarketplaceSite::REASON_REVOKED])->save();
        $this->assertRefused($this->get($unsigned('https://'.self::SITE)));
        $site->forceFill(['status' => MarketplaceSite::STATUS_ACTIVE, 'pending_reason' => null])->save();
        $this->assertRefused($this->get($unsigned('https://lookalike.example.net')));
        $this->assertRefused($this->get($unsigned('http://'.self::SITE)));
        $this->assertRefused($this->get($unsigned('https://'.self::SITE.':8443')));
        $this->assertRefused($this->get($unsigned(self::STORE)));

        config(['nuvabill.whatsapp_connect.unsigned' => false]);
        $this->assertRefused($this->get($unsigned('https://'.self::SITE)));

        // Someone's own signup page lists the sites that may use it.
        config(['nuvabill.whatsapp_connect.sites' => ['clients.example.org']]);
        $this->assertOpens($this->get($unsigned('https://clients.example.org')));
        $this->assertRefused($this->get($unsigned('https://'.self::SITE)));
    }

    public function test_the_page_opens_for_its_own_admin_area_before_it_is_opened_to_everyone(): void
    {
        config(['nuvabill.whatsapp_connect.open' => false, 'app.url' => self::STORE]);
        URL::forceRootUrl(self::STORE);
        URL::forceScheme('https');

        $link = (string) app(WhatsAppConnectLink::class)->url(self::STATE);
        parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
        $this->assertSame('0', $query['site']);

        $this->assertOpens($this->get($link));
        $this->assertRefused($this->get(self::STORE.'/connect/whatsapp?'.http_build_query(['origin' => self::STORE, 'state' => self::STATE])));
        $this->assertRefused($this->get(self::STORE.'/connect/whatsapp?'.http_build_query(['state' => str_repeat('b', 32)] + $query)));

        // The same link on another site's page is worth nothing there.
        $this->assertRefused($this->get('https://elsewhere.example.net/connect/whatsapp?'.http_build_query($query)));
    }

    public function test_metas_code_is_only_swapped_as_the_last_step_of_a_signup_the_page_showed(): void
    {
        $site = $this->storeSite();
        parse_str((string) parse_url($this->linkFrom(self::SITE, $site->id), PHP_URL_QUERY), $query);
        Http::fake(['graph.facebook.com/*' => Http::response(['access_token' => 'EAABbusiness'])]);
        $exchange = fn (array $values): TestResponse => $this->postJson(self::STORE.'/connect/whatsapp/exchange', ['code' => 'abc'] + $values);

        $exchange([])->assertForbidden();
        $exchange(['origin' => 'https://lookalike.example.net', 'state' => self::STATE])->assertForbidden();
        $exchange(['origin' => 'https://lookalike.example.net'] + $query)->assertForbidden()
            ->assertJsonPath('message', 'This window was open for too long. Close it and start again from Settings → Chat apps.');
        Http::assertNothingSent();

        $exchange($query)->assertOk()->assertExactJson(['token' => 'EAABbusiness']);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'oauth/access_token') && $request['client_secret'] === 'shh' && $request['code'] === 'abc');

        // Scanning the QR code takes a while: a signup that started in time may still finish.
        $this->travel(WhatsAppConnectLink::MINUTES + WhatsAppConnectLink::FINISH_MINUTES - 1)->minutes();
        $exchange($query)->assertOk();
        $this->travel(2)->minutes();
        $exchange($query)->assertForbidden();
    }

    public function test_the_admin_area_signs_the_link_or_says_what_is_missing(): void
    {
        config(['app.url' => 'https://'.self::SITE]);
        URL::forceRootUrl('https://'.self::SITE);
        URL::forceScheme('https');
        $this->signInAdmin();
        Http::fake([self::STORE.'/connect/whatsapp/status' => Http::response(['ready' => true])]);

        // Not connected to the marketplace: the page would stay closed, so staff are told what to do.
        $this->get(route('admin.settings.chat.edit'))->assertOk()
            ->assertSee('To connect by QR code, this site must be connected to the marketplace as billing.example.com.')
            ->assertDontSee('Connect with a QR code')
            ->assertSee('Permanent access token');

        $this->setSettings(['marketplace.site_key' => self::KEY, 'marketplace.site_id' => 7, 'marketplace.site_status' => 'active']);
        $page = $this->get(route('admin.settings.chat.edit'))->assertOk()
            ->assertSee('Connect with a QR code')
            ->assertDontSee('this site must be connected to the marketplace');

        // The button's link names this site and carries its signature.
        $this->assertStringContainsString('origin=https%3A%2F%2Fbilling.example.com', (string) $page->getContent());
        $this->assertStringContainsString('state='.session('chat.whatsapp_state'), (string) $page->getContent());
        $this->assertMatchesRegularExpression('/site=7.{1,8}time=\d+.{1,8}signature=[a-f0-9]{64}[^a-f0-9]/', (string) $page->getContent());

        // The store no longer knows the key: connect again first.
        $this->setSettings(['marketplace.site_status' => 'reconnect']);
        $this->get(route('admin.settings.chat.edit'))->assertSee('this site must be connected to the marketplace')->assertDontSee('Connect with a QR code');
    }

    private function assertOpens(TestResponse $page): void
    {
        $page->assertOk()->assertSee('connect.facebook.net')->assertSee('Continue with Facebook');
    }

    private function assertRefused(TestResponse $page): void
    {
        $page->assertOk()
            ->assertSee('This page must be opened from Settings → Chat apps in your Nuvabill admin area.')
            ->assertSee('It only opens for a site that is connected to the marketplace.')
            ->assertDontSee('connect.facebook.net')
            ->assertDontSee('Continue with Facebook');
        $this->assertStringNotContainsString('facebook', (string) $page->headers->get('Content-Security-Policy'));
        $this->assertNotSame('unsafe-none', $page->headers->get('Cross-Origin-Opener-Policy'));
    }

    /**
     * The row the store keeps for a site that connected to the marketplace.
     */
    private function storeSite(string $host = self::SITE): MarketplaceSite
    {
        return MarketplaceSite::query()->create([
            'host' => $host,
            'ip' => '45.33.10.20',
            'ip_family' => 'ipv4',
            'key_hash' => MarketplaceSite::hashKey(self::KEY),
            'key_created_at' => now(),
            'status' => MarketplaceSite::STATUS_ACTIVE,
            'last_seen_at' => now(),
        ]);
    }

    /**
     * The link the admin area of a connected site makes for its "Connect with a QR code" button.
     */
    private function linkFrom(string $host, int $siteId): string
    {
        config(['app.url' => 'https://'.$host]);
        URL::forceRootUrl('https://'.$host);
        URL::forceScheme('https');
        $this->setSettings(['marketplace.site_key' => self::KEY, 'marketplace.site_id' => $siteId, 'marketplace.site_status' => 'active']);

        return (string) app(WhatsAppConnectLink::class)->url(self::STATE);
    }
}
