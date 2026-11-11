<?php

namespace Tests\Feature\Marketplace;

use App\Http\Controllers\Marketplace\StoreApiController;
use App\Marketplace\Store\ItemPublisher;
use App\Marketplace\Store\LicenseService;
use App\Marketplace\Store\SigningKey;
use App\Marketplace\Store\SourceNetwork;
use App\Marketplace\Store\VersionUploader;
use App\Models\Client;
use App\Models\Developer;
use App\Models\License;
use App\Models\MarketplaceDownload;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceSite;
use App\Providers\MarketplaceStoreServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The store's limits on license keys and install counts: which test sites a key works on, where
 * test sites may download from, when staff are warned about a key, and what counts as an install.
 */
class StoreLimitsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nuvabill-limits-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/storage/app/private');
        $this->app->useStoragePath($this->dir.'/storage');

        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();
        app(SigningKey::class)->generate();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_networks_group_the_addresses_of_one_place(): void
    {
        $this->assertSame('203.0.113.0/24', SourceNetwork::of('203.0.113.9'));
        $this->assertSame(SourceNetwork::of('203.0.113.9'), SourceNetwork::of('203.0.113.200'));
        $this->assertSame(SourceNetwork::of('203.0.113.9'), SourceNetwork::of('::ffff:203.0.113.9'));
        $this->assertNotSame(SourceNetwork::of('203.0.113.9'), SourceNetwork::of('203.0.114.9'));
        $this->assertSame('2001:db8:12::/48', SourceNetwork::of('2001:DB8:12:ff00::1'));
        $this->assertSame(SourceNetwork::of('2001:db8:12::1'), SourceNetwork::of('2001:db8:12:ffff:1:2:3:4'));
        $this->assertNotSame(SourceNetwork::of('2001:db8:12::1'), SourceNetwork::of('2001:db8:13::1'));
        $this->assertNull(SourceNetwork::of('not an address'));
        $this->assertNull(SourceNetwork::of(null));
    }

    public function test_a_key_works_on_local_copies_and_on_test_copies_of_its_own_site_only(): void
    {
        $item = $this->liveItem('aurora', 5900);
        $license = $this->license($item, 'billing.example.org');
        $licenses = app(LicenseService::class);

        foreach (['localhost', '127.0.0.1', 'shop.test', 'billing.local', 'staging.billing.example.org', 'dev.billing.example.org', 'staging.example.org', 'https://www.dev.example.org/admin'] as $site) {
            $this->assertTrue($licenses->verify($item, $license->key, $site)['valid'], $site);
        }

        foreach (['staging.other.example.net', 'dev.example.net', 'staging.org', 'dev.shop.example.org', 'stagingbilling.example.org', 'other.example.org'] as $site) {
            $this->assertSame('wrong_site', $licenses->verify($item, $license->key, $site)['status'], $site);
        }

        $this->assertSame('billing.example.org', $license->fresh()->site, 'Test sites never take the key.');

        // A key without a site: local and test copies leave it free, the first other name becomes its site.
        $free = $this->license($item, null);
        $this->assertTrue($licenses->verify($item, $free->key, 'shop.test')['valid']);
        $this->assertTrue($licenses->verify($item, $free->key, 'staging.shop.example.net')['valid']);
        $this->assertNull($free->fresh()->site);
        $this->assertTrue($licenses->verify($item, $free->key, 'shop.example.net')['valid']);
        $this->assertSame('shop.example.net', $free->fresh()->site);
        $this->assertTrue($licenses->verify($item, $free->key, 'staging.shop.example.net')['valid']);
        $this->assertSame('wrong_site', $licenses->verify($item, $free->key, 'dev.other.example.net')['status']);
    }

    public function test_test_sites_download_from_a_few_networks_and_keep_passing_the_daily_check(): void
    {
        $item = $this->liveItem('aurora', 5900);
        $license = $this->license($item, 'billing.example.org');
        $download = fn (string $ip, string $site) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => $site]);

        foreach (range(1, LicenseService::TEST_DOWNLOAD_NETWORKS) as $place) {
            $download("198.51.{$place}.7", $place % 2 === 0 ? 'localhost' : 'staging.billing.example.org')->assertOk();
        }

        $download('203.0.113.7', 'shop.test')->assertForbidden()
            ->assertJsonPath('message', 'This key was used for test sites from too many places in the last 30 days. Use it on its own site, or try again later.');
        $this->assertSame(LicenseService::TEST_DOWNLOAD_NETWORKS, MarketplaceDownload::query()->count(), 'A refused download is not kept.');

        // A place that downloaded before still can, and the key's own site is never limited.
        $download('198.51.1.99', 'localhost')->assertOk();
        $download('203.0.113.7', 'billing.example.org')->assertOk();

        // Development goes on: the daily check of a local copy passes from anywhere.
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->postJson(route('marketplace.api.licenses.check'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'shop.test'])
            ->assertJson(['valid' => true]);

        // A month later the places are free again.
        $this->travel(31)->days();
        $download('203.0.113.7', 'shop.test')->assertOk();
    }

    public function test_staff_are_warned_when_a_key_is_used_from_many_networks_or_sites(): void
    {
        $item = $this->liveItem('aurora', 5900);
        $license = $this->license($item, 'billing.example.org');
        $licenses = app(LicenseService::class);
        $check = fn (string $ip, string $site) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('marketplace.api.licenses.check'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => $site])->assertOk();

        // One office: many addresses, one network, and the key's own test sites.
        foreach (range(1, 20) as $host) {
            $check("198.51.100.{$host}", $host % 2 === 0 ? 'localhost' : 'dev.billing.example.org');
        }

        foreach (range(1, LicenseService::SHARED_NETWORK_LIMIT - 1) as $place) {
            $check("203.0.{$place}.5", 'billing.example.org');
        }

        $this->assertSame(['billing.example.org'], $licenses->recentSites($license));
        $this->assertCount(LicenseService::SHARED_NETWORK_LIMIT, $licenses->recentNetworks($license));
        $this->assertFalse($licenses->looksShared($license));

        // One more place, under a test name.
        $check('192.0.2.5', 'localhost');
        $this->assertTrue($licenses->looksShared($license));

        $this->signInAdmin();
        $this->get(route('admin.store.licenses.show', $license))->assertOk()
            ->assertSee('This key was used on 1 different sites and from '.(LicenseService::SHARED_NETWORK_LIMIT + 1).' different networks in the last 30 days.');

        // Old checks no longer count.
        $this->travel(31)->days();
        $this->assertFalse($licenses->looksShared($license));

        // Test copies of other sites are sites of their own.
        foreach (['staging.one.example.net', 'dev.two.example.net', 'staging.three.example.net', 'dev.four.example.net'] as $site) {
            $check('198.51.100.1', $site);
        }

        $this->assertCount(4, $licenses->recentSites($license));
        $this->assertTrue($licenses->looksShared($license));
    }

    public function test_a_connected_site_is_checked_under_the_address_it_connected_with(): void
    {
        $item = $this->liveItem('aurora', 5900);
        $license = $this->license($item, 'billing.example.org');
        $key = 'nvb_site_'.str_repeat('a', 43);
        $this->site($key, 'shop.example.net');
        $body = fn (string $site): array => ['slug' => 'aurora', 'license_key' => $license->key, 'site' => $site];

        // Without a connection, the name that is sent counts.
        $this->postJson(route('marketplace.api.licenses.check'), $body('localhost'))->assertJson(['valid' => true]);

        // With one, the connected address counts, whatever name is sent.
        $this->withToken($key);
        $this->postJson(route('marketplace.api.licenses.check'), $body('localhost'))->assertJson(['valid' => false, 'status' => 'wrong_site']);
        $this->postJson(route('marketplace.api.licenses.check'), $body('billing.example.org'))->assertJson(['valid' => false, 'status' => 'wrong_site']);
        $this->postJson(route('marketplace.api.download'), $body('shop.test'))->assertForbidden();

        // The site the key is for, connected: it passes, also when it sends another name.
        $own = 'nvb_site_'.str_repeat('b', 43);
        $this->site($own, 'www.billing.example.org');
        $this->withToken($own);
        $this->postJson(route('marketplace.api.licenses.check'), $body('billing.example.org'))->assertJson(['valid' => true]);
        $this->postJson(route('marketplace.api.download'), $body('something.else.example'))->assertOk();
        $this->assertSame('billing.example.org', MarketplaceDownload::query()->sole()->site);
    }

    public function test_a_free_item_counts_each_connected_site_once_and_other_callers_once_per_network(): void
    {
        $item = $this->liveItem('paper', 0);
        $installs = fn (): int => $item->fresh()->installs_count;
        $download = fn (string $ip, string $site) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => $site])->assertOk();

        // Callers that are not connected: many names and addresses from one network are one install.
        foreach (range(1, 8) as $number) {
            $download("198.51.100.{$number}", "site{$number}.example.org");
            $download("2001:db8:12:{$number}::1", "ipv6-{$number}.example.org");
        }

        $this->assertSame(2, $installs(), 'One install per network.');
        $download('2001:db8:13::1', 'elsewhere.example.org');
        $this->assertSame(3, $installs());

        // A known name from a new network is the same site that moved.
        $download('192.0.2.50', 'site1.example.org');
        $this->assertSame(3, $installs());

        // Connected sites count one each, also on a network that was counted before.
        $first = 'nvb_site_'.str_repeat('a', 43);
        $second = 'nvb_site_'.str_repeat('b', 43);
        $waiting = 'nvb_site_'.str_repeat('c', 43);
        $connected = $this->site($first, 'one.hostco.net');
        $this->site($second, 'two.hostco.net');
        $this->site($waiting, 'three.hostco.net', MarketplaceSite::STATUS_PENDING);

        $this->withToken($first);
        $download('198.51.100.20', 'made-up.example.org');
        $this->assertSame(4, $installs());
        $this->assertSame(['one.hostco.net', $connected->id, '198.51.100.0/24'], array_values(MarketplaceDownload::query()->latest('id')->first()->only(['site', 'marketplace_site_id', 'network'])));

        // The same site again, from another address and with another name, is not a new install.
        $download('192.0.2.77', 'another.example.org');
        $this->assertSame(4, $installs());

        $this->withToken($second);
        $download('198.51.100.20', 'two.hostco.net');
        $this->assertSame(5, $installs());

        // A site that still waits for staff counts like a caller that is not connected.
        $this->withToken($waiting);
        $download('198.51.100.20', 'three.hostco.net');
        $this->assertSame(5, $installs());
    }

    public function test_a_site_counted_before_connected_sites_were_noted_is_not_counted_again(): void
    {
        $item = $this->liveItem('paper', 0);
        $key = 'nvb_site_'.str_repeat('a', 43);
        $this->site($key, 'one.hostco.net');

        // A download from before the update: no network and no connected site on it.
        MarketplaceDownload::create(['marketplace_item_id' => $item->id, 'marketplace_version_id' => $item->latest_version_id, 'site' => 'one.hostco.net', 'ip' => '45.33.10.20']);
        $item->forceFill(['installs_count' => 1])->save();

        $this->withToken($key)->withServerVariables(['REMOTE_ADDR' => '45.33.10.99'])
            ->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => 'one.hostco.net'])->assertOk();
        $this->assertSame(1, $item->fresh()->installs_count);

        // The same for a caller that is not connected and comes from the address it had before.
        MarketplaceDownload::create(['marketplace_item_id' => $item->id, 'marketplace_version_id' => $item->latest_version_id, 'site' => 'old.example.org', 'ip' => '203.0.113.9']);
        $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
            ->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => 'renamed.example.org'])->assertOk();
        $this->assertSame(1, $item->fresh()->installs_count);
    }

    public function test_only_a_few_installs_a_day_count_from_callers_that_are_not_connected(): void
    {
        $item = $this->liveItem('paper', 0);
        $limit = StoreApiController::UNCONNECTED_INSTALLS_PER_DAY;
        $download = fn (string $ip, string $site) => $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => $site])->assertOk();

        foreach (range(1, $limit + 10) as $place) {
            $download("198.{$place}.100.1", "site{$place}.example.org");
        }

        $this->assertSame($limit, $item->fresh()->installs_count);
        $this->assertSame($limit + 10, MarketplaceDownload::query()->count(), 'Every download is still noted.');

        // A connected site always counts.
        $key = 'nvb_site_'.str_repeat('a', 43);
        $this->site($key, 'one.hostco.net');
        $this->withToken($key);
        $download('45.33.10.20', 'one.hostco.net');
        $this->assertSame($limit + 1, $item->fresh()->installs_count);

        // The next day new places count again.
        $this->flushHeaders()->travel(1)->days();
        $download('192.0.2.1', 'tomorrow.example.org');
        $this->assertSame($limit + 2, $item->fresh()->installs_count);
    }

    private function license(MarketplaceItem $item, ?string $site): License
    {
        return License::create([
            'key' => License::newKey(),
            'marketplace_item_id' => $item->id,
            'client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las'])->id,
            'site' => $site,
            'status' => License::STATUS_ACTIVE,
            'updates_until' => now()->addYear(),
        ]);
    }

    private function site(string $key, string $host, string $status = MarketplaceSite::STATUS_ACTIVE): MarketplaceSite
    {
        return MarketplaceSite::query()->create([
            'host' => $host,
            'ip' => '45.33.10.20',
            'ip_family' => 'ipv4',
            'key_hash' => MarketplaceSite::hashKey($key),
            'key_created_at' => now(),
            'status' => $status,
            'last_seen_at' => now(),
        ]);
    }

    private function liveItem(string $slug, int $price): MarketplaceItem
    {
        $developer = Developer::query()->firstOrCreate(['slug' => 'nuvabill'], ['name' => 'Nuvabill', 'is_official' => true, 'status' => 'active']);
        $item = $developer->items()->create(['slug' => $slug, 'type' => 'theme', 'name' => ucfirst($slug), 'summary' => 'Test item.', 'price' => $price, 'update_price' => 0, 'currency' => 'USD', 'status' => MarketplaceItem::STATUS_DRAFT]);

        $path = $this->dir.'/'.$slug.'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('theme.json', (string) json_encode(['slug' => $slug, 'name' => ucfirst($slug), 'version' => '1.0.0', 'requires' => '>=0.3.0', 'paid' => $price > 0]));
        $zip->addFromString('views/layouts/app.blade.php', '<html></html>');
        $zip->close();

        app(ItemPublisher::class)->approve(app(VersionUploader::class)->upload($item, $path));

        return $item->refresh();
    }
}
