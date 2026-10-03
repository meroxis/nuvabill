<?php

namespace Tests\Feature\Marketplace;

use App\Enums\BillingCycle;
use App\Marketplace\PackageType;
use App\Marketplace\Store\LicenseService;
use App\Models\Client;
use App\Models\License;
use App\Models\MarketplaceItem;
use App\Providers\MarketplaceStoreServiceProvider;
use App\Support\Branding;
use App\Support\WhiteLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhiteLabelTest extends TestCase
{
    use RefreshDatabase;

    private const CHECK_URL = 'https://store.example.test/api/marketplace/v1/licenses/check';

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.marketplace.url' => 'https://store.example.test']);
    }

    public function test_a_valid_key_hides_the_nuvabill_credit(): void
    {
        Http::fake([self::CHECK_URL => Http::response(['valid' => true, 'status' => 'active', 'message' => ''])]);
        $this->signInAdmin();

        $this->get(route('store.index'))->assertSee('Powered by');
        $this->put(route('admin.settings.license.update'), ['key' => 'nvb-aaaa-bbbb-cccc-dddd'])->assertSessionHas('status');

        $this->assertSame('NVB-AAAA-BBBB-CCCC-DDDD', app(WhiteLabel::class)->key());
        $this->assertFalse(Branding::showPoweredBy());
        $this->get(route('store.index'))->assertDontSee('Powered by');
        Http::assertSent(fn ($request): bool => $request['slug'] === 'white-label' && $request['license_key'] === 'NVB-AAAA-BBBB-CCCC-DDDD');

        $this->put(route('admin.settings.license.update'), ['key' => ''])->assertSessionHas('status');
        $this->assertTrue(Branding::showPoweredBy());
    }

    public function test_an_invalid_key_keeps_the_credit_and_explains_why(): void
    {
        Http::fake([self::CHECK_URL => Http::response(['valid' => false, 'status' => 'expired', 'message' => 'This license ended on 01 Sep 2026.'])]);
        $this->signInAdmin();

        $this->put(route('admin.settings.license.update'), ['key' => 'NVB-OLD1-OLD2-OLD3-OLD4'])->assertSessionHas('error', 'This license ended on 01 Sep 2026.');
        $this->assertTrue(Branding::showPoweredBy());
        $this->get(route('admin.settings.license.edit'))->assertOk()->assertSee('This license ended on 01 Sep 2026.');
    }

    public function test_a_store_outage_keeps_a_good_license_for_the_grace_period_only(): void
    {
        Http::fake([self::CHECK_URL => Http::sequence()
            ->push(['valid' => true, 'status' => 'active', 'message' => ''])
            ->push('down', 503)
            ->push('down', 503)]);
        $whiteLabel = app(WhiteLabel::class);
        $whiteLabel->saveKey('NVB-AAAA-BBBB-CCCC-DDDD');

        $this->travel(5)->days();
        $whiteLabel->check();
        $this->assertTrue($whiteLabel->isActive(), 'A short outage changes nothing.');

        $this->travel(20)->days();
        $whiteLabel->check();
        $this->assertFalse($whiteLabel->isActive(), 'After the grace period the credit comes back.');
    }

    public function test_the_public_demo_always_shows_the_credit(): void
    {
        Http::fake([self::CHECK_URL => Http::response(['valid' => true, 'status' => 'active', 'message' => ''])]);
        app(WhiteLabel::class)->saveKey('NVB-AAAA-BBBB-CCCC-DDDD');
        config(['nuvabill.demo' => true]);

        $this->assertTrue(Branding::showPoweredBy());
    }

    public function test_the_store_sells_it_yearly_and_it_stops_when_not_renewed(): void
    {
        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();

        $this->artisan('nuvabill:white-label-product', ['price' => '99'])->assertSuccessful();
        $item = MarketplaceItem::query()->where('slug', 'white-label')->sole();
        $this->assertSame(PackageType::License, $item->type);
        $this->assertSame(9900, $item->product->prices()->sole()->price);
        $this->get(route('marketplace.white-label'))->assertOk()->assertSee('$99.00');
        $this->getJson(route('marketplace.api.catalog'))->assertJsonMissing(['slug' => 'white-label']);

        $license = License::create(['key' => License::newKey(), 'marketplace_item_id' => $item->id, 'client_id' => Client::factory()->create()->id, 'status' => 'active', 'updates_until' => today()->addMonth()]);
        $this->assertTrue(app(LicenseService::class)->verify($item, $license->key, 'billing.example.org')['valid']);

        $license->update(['updates_until' => today()->subDay()]);
        $result = app(LicenseService::class)->verify($item, $license->key, 'billing.example.org');
        $this->assertFalse($result['valid']);
        $this->assertSame('expired', $result['status']);
    }

    public function test_clearing_the_renewal_in_the_store_admin_keeps_the_license_yearly(): void
    {
        config(['nuvabill.marketplace.store' => true]);
        (new MarketplaceStoreServiceProvider($this->app))->enable();
        $this->artisan('nuvabill:white-label-product', ['price' => '99', 'renewal' => '49'])->assertSuccessful();
        $item = MarketplaceItem::query()->where('slug', 'white-label')->sole();

        $this->signInAdmin();
        $this->put(route('admin.store.items.update', $item), ['status' => 'live', 'price' => '99', 'update_price' => ''])->assertRedirect();

        $price = $item->refresh()->product->prices()->sole();
        $this->assertSame(BillingCycle::Annually, $price->billing_cycle, 'A blank renewal means the first year price, not a key that never ends.');
        $this->assertSame(9900, $price->price);
        $this->assertSame(0, $price->setup_fee);
        $this->get(route('marketplace.white-label'))->assertOk()->assertSee('Then $99.00 a year.');
    }
}
