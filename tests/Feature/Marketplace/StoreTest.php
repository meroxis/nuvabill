<?php

namespace Tests\Feature\Marketplace;

use App\Billing\PaymentRecorder;
use App\Marketplace\PackageSignature;
use App\Marketplace\Store\ItemPublisher;
use App\Marketplace\Store\SigningKey;
use App\Marketplace\Store\VersionUploader;
use App\Models\Client;
use App\Models\Developer;
use App\Models\DeveloperEarning;
use App\Models\License;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use App\Models\Order;
use App\Providers\MarketplaceStoreServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

class StoreTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nuvabill-store-'.uniqid();
        File::ensureDirectoryExists($this->dir.'/storage/app/private');
        $this->app->useStoragePath($this->dir.'/storage');

        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();

        $this->publicKey = app(SigningKey::class)->generate();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_developer_submits_an_item_and_staff_approve_and_sign_it(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('developer.join.store'), ['name' => 'Raz Studio', 'agree' => '1', 'payout_method' => 'wayl'])
            ->assertRedirect(route('developer.dashboard'));
        $this->get(route('developer.dashboard'))->assertOk()->assertSee('Raz Studio');

        $this->post(route('developer.items.store'), [
            'name' => 'Paper', 'slug' => 'paper', 'type' => 'theme', 'summary' => 'A calm light theme.',
            'price' => '25', 'update_price' => '9', 'own_code' => '1',
            'package' => $this->upload($this->zip('paper', '1.0.0', 'theme.json', ['requires' => '>=0.3.0'])),
        ])->assertRedirect(route('developer.items.show', 'paper'));

        $version = MarketplaceVersion::query()->sole();
        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $version->status);
        $this->get(route('developer.items.show', 'paper'))->assertOk()->assertSee('Readable code')->assertSee('In review');

        $admin = $this->signInAdmin();
        $this->get(route('admin.store.reviews.index'))->assertRedirect(route('admin.store.reviews.show', $version));
        $this->get(route('admin.store.reviews.show', $version))->assertOk()->assertSee('Paper')->assertSee('Approve and sign');

        $this->post(route('admin.store.reviews.approve', $version), ['message' => 'Looks great.'])->assertRedirect(route('admin.store.reviews.index'));

        $version->refresh();
        $item = MarketplaceItem::query()->sole();
        $this->assertSame(MarketplaceVersion::STATUS_APPROVED, $version->status);
        $this->assertTrue(PackageSignature::verify('paper', '1.0.0', $version->sha256, (string) $version->signature, $this->publicKey));
        $this->assertTrue($item->isLive());
        $this->assertSame($admin->id, $version->reviewer_id);
        $this->assertSame(2500, $item->product->prices->sole()->price + $item->product->prices->sole()->setup_fee);
        $this->assertSame(900, $item->product->prices->sole()->price, 'Renewals buy a year of updates.');

        $this->get(route('marketplace.index'))->assertOk()->assertSee('Paper');
        $this->get(route('marketplace.show', $item))->assertOk()->assertSee('$25.00')->assertSee('Website address for this license');
    }

    public function test_packages_with_encoded_code_fail_the_automatic_checks(): void
    {
        $item = $this->item('shady', 'addon', 0);
        $zip = $this->zip('shady', '1.0.0', 'extension.json', ['type' => 'addon', 'namespace' => 'Shady\\', 'class' => 'Shady\\Addon'], [
            'src/Addon.php' => "<?php //0046a\n// ionCube Loader\n",
            'src/Run.php' => "<?php\nshell_exec(\$_GET['c']);\n",
        ]);

        $version = app(VersionUploader::class)->upload($item, $zip);

        $this->assertSame(MarketplaceVersion::STATUS_CHANGES, $version->status);
        $checks = collect($version->checks)->keyBy('key');
        $this->assertSame('fail', $checks['encoded']['level']);
        $this->assertSame('warn', $checks['functions']['level']);
        $this->assertStringContainsString('src/Run.php:2 shell_exec()', $checks['functions']['text']);
        $this->assertStringContainsString('ionCube', $version->messages()->sole()->message.' ionCube');
    }

    public function test_paid_downloads_need_a_license_tied_to_one_site_and_carry_a_stamp(): void
    {
        $item = $this->liveItem('aurora', 'theme', 5900, 1900);
        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $item->id, 'client_id' => Client::factory()->create()->id, 'status' => 'active', 'updates_until' => now()->addYear()]);

        $this->getJson(route('marketplace.api.catalog', ['nuvabill' => '0.3.0']))->assertOk()->assertJsonPath('items.0.slug', 'aurora')->assertJsonPath('items.0.price', 5900);

        $this->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'site' => 'billing.example.org'])->assertForbidden();

        $response = $this->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'https://www.billing.example.org/admin', 'nuvabill' => '0.3.0'])->assertOk();
        $this->assertSame('billing.example.org', $license->fresh()->site);

        $file = $this->get($response->json('url'))->assertOk();
        $bytes = $file->streamedContent() ?: (string) file_get_contents($file->baseResponse->getFile()->getPathname());
        $this->assertSame($response->json('sha256'), hash('sha256', $bytes));
        $this->assertTrue(PackageSignature::verify('aurora', '1.0.0', $response->json('sha256'), $response->json('signature'), $this->publicKey));

        $path = $this->dir.'/downloaded.zip';
        file_put_contents($path, $bytes);
        $zip = new ZipArchive;
        $zip->open($path);
        $stamp = json_decode((string) $zip->getFromName('.nuvabill-license'), true);
        $zip->close();
        $this->assertSame($license->publicId(), $stamp['license']);

        $this->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'other.example.net'])
            ->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_starts_with($message, 'This key is used on billing.example.org.'));
        $this->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'localhost'])->assertOk();

        $this->postJson(route('marketplace.api.licenses.check'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertJson(['valid' => true]);

        $license->update(['status' => License::STATUS_REVOKED]);
        $this->postJson(route('marketplace.api.licenses.check'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertJson(['valid' => false, 'status' => 'revoked']);
    }

    public function test_buying_an_item_issues_a_license_and_pays_the_developer_their_share(): void
    {
        $item = $this->liveItem('swift', 'orderform', 3900, 1500);
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('cart.store'), ['product_id' => $item->product_id, 'billing_cycle' => 'annually', 'domain' => 'billing.example.org']);
        $this->post(route('checkout.store'))->assertRedirect();

        $invoice = Order::query()->sole()->invoice;
        $this->assertSame(3900, $invoice->total);

        app(PaymentRecorder::class)->record($invoice, 3900, 'banktransfer', 'wire-1');

        $license = License::query()->sole();
        $this->assertSame('billing.example.org', $license->site);
        $this->assertStringStartsWith('NVB-', $license->key);
        $this->assertTrue($license->updates_until->isAfter(now()->addMonths(11)));

        $earning = DeveloperEarning::query()->sole();
        $this->assertSame(3900, $earning->gross);
        $this->assertSame(3237, $earning->developer_share, '83% of $39.00.');
        $this->assertSame(663, $earning->fee);
        $this->get(route('client.services.show', $license->service))->assertSee($license->key)->assertSee('Move to another site');

        $this->signInAdmin();
        $this->post(route('admin.store.payouts.store'), ['developer_id' => $item->developer_id, 'reference' => 'WAYL-99'])->assertSessionHas('status');
        $this->assertNotNull($earning->fresh()->payout_id);
        $this->assertSame(0, $item->developer->balance('USD'));
    }

    private function item(string $slug, string $type, int $price, int $updatePrice = 0): MarketplaceItem
    {
        $developer = Developer::query()->firstOrCreate(['slug' => 'nuvabill'], ['name' => 'Nuvabill', 'is_official' => true, 'status' => 'active']);

        return $developer->items()->create(['slug' => $slug, 'type' => $type, 'name' => ucfirst($slug), 'summary' => 'Test item.', 'price' => $price, 'update_price' => $updatePrice, 'currency' => 'USD', 'status' => MarketplaceItem::STATUS_DRAFT]);
    }

    private function liveItem(string $slug, string $type, int $price, int $updatePrice): MarketplaceItem
    {
        $item = $this->item($slug, $type, $price, $updatePrice);
        $manifest = $type === 'orderform' ? 'orderform.json' : 'theme.json';
        $version = app(VersionUploader::class)->upload($item, $this->zip($slug, '1.0.0', $manifest, ['requires' => '>=0.3.0', 'paid' => true]));
        app(ItemPublisher::class)->approve($version);

        return $item->refresh();
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    private function zip(string $slug, string $version, string $manifestFile, array $manifest = [], array $files = []): string
    {
        $path = $this->dir.'/'.$slug.'-'.$version.'-'.uniqid().'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString($manifestFile, (string) json_encode($manifest + ['slug' => $slug, 'name' => ucfirst($slug), 'version' => $version]));
        $zip->addFromString('views/layouts/app.blade.php', '<html></html>');

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    private function upload(string $path): UploadedFile
    {
        return new UploadedFile($path, basename($path), 'application/zip', null, true);
    }
}
