<?php

namespace Tests\Feature\Marketplace;

use App\Marketplace\Store\SigningKey;
use App\Models\Client;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use App\Providers\MarketplaceStoreServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;
use ZipArchive;

/**
 * The listing of a new item (texts, links, screenshots) is only public once a reviewer approved
 * it, and the approval is for the listing that reviewer saw.
 */
class ListingReviewTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().'/nuvabill-listing-'.uniqid();
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

    public function test_screenshots_are_public_only_while_the_item_is_live(): void
    {
        $item = $this->submitItem(['screenshots' => [UploadedFile::fake()->image('shot.png', 800, 500)]]);
        $version = MarketplaceVersion::query()->sole();
        $shot = $item->screenshots[0];

        // Waiting for review: not public, and not for the developer's staff link either.
        $this->get(route('marketplace.media', ['calm', $shot]))->assertNotFound();
        $this->get(route('admin.store.items.media', [$item, $shot]))->assertRedirect();
        $this->get(route('developer.items.show', $item))->assertOk();

        // The reviewer sees the listing and opens the screenshots through the staff link.
        $this->signInAdmin();
        $this->get(route('admin.store.reviews.show', $version))->assertOk()
            ->assertSee('This listing goes live when you approve the first version.')
            ->assertSee('A calm light theme.')
            ->assertSee('https://demo.example.org/calm')
            ->assertSee(route('admin.store.items.media', [$item, $shot]), false)
            ->assertDontSee(route('marketplace.media', ['calm', $shot]), false);
        $this->get(route('admin.store.items.media', [$item, $shot]))->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get(route('admin.store.items.media', [$item, 'app.blade.php']))->assertNotFound();

        $this->post(route('admin.store.reviews.approve', $version), ['seen' => $item->listingFingerprint()])->assertRedirect(route('admin.store.reviews.index'));

        // Live: public, and no longer on the staff link.
        $this->get(route('marketplace.media', ['calm', $shot]))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->get(route('admin.store.items.media', [$item->fresh(), $shot]))->assertNotFound();
        $this->getJson(route('marketplace.api.catalog'))->assertJsonPath('items.0.screenshots.0', route('marketplace.media', ['calm', $shot]));

        // Staff hide the item: its screenshots leave the public link again.
        $this->put(route('admin.store.items.update', $item), ['status' => 'hidden', 'price' => '0'])->assertSessionHasNoErrors();
        $this->get(route('marketplace.media', ['calm', $shot]))->assertNotFound();
        $this->get(route('admin.store.items.media', [$item->fresh(), $shot]))->assertOk();
    }

    public function test_the_first_approval_is_for_the_listing_the_reviewer_saw(): void
    {
        $item = $this->submitItem();
        $version = MarketplaceVersion::query()->sole();

        $this->signInAdmin();
        $page = $this->get(route('admin.store.reviews.show', $version))->assertOk()->assertSee('https://demo.example.org/calm');
        $this->assertSame(1, preg_match('/name="seen" value="([a-f0-9]{64})"/', (string) $page->getContent(), $match));
        $seen = $match[1];
        $this->assertSame($item->listingFingerprint(), $seen);

        // The developer changes the listing while the reviewer is looking at the old one.
        $this->put(route('developer.items.update', $item), ['name' => 'Paper', 'summary' => 'A calm light theme.', 'demo_url' => 'https://other.example.net/sign-in'])
            ->assertSessionHas('status', 'Listing saved.');

        $this->post(route('admin.store.reviews.approve', $version), ['seen' => $seen])
            ->assertSessionHas('error', 'The developer changed the listing after you opened this page. Look at the listing again, then decide.');
        $this->post(route('admin.store.reviews.approve', $version))->assertSessionHas('error');

        $this->assertSame(MarketplaceVersion::STATUS_PENDING, $version->fresh()->status);
        $this->assertNull($version->fresh()->signature);
        $this->assertFalse($item->fresh()->isLive());
        $this->get(route('marketplace.show', $item))->assertNotFound();

        // The reviewer looks again, sees the new link and decides about that.
        $page = $this->get(route('admin.store.reviews.show', $version))->assertOk()->assertSee('https://other.example.net/sign-in');
        preg_match('/name="seen" value="([a-f0-9]{64})"/', (string) $page->getContent(), $match);
        $this->post(route('admin.store.reviews.approve', $version), ['seen' => $match[1]])->assertRedirect(route('admin.store.reviews.index'));

        $this->assertSame(MarketplaceVersion::STATUS_APPROVED, $version->fresh()->status);
        $this->assertTrue($item->fresh()->isLive());

        // From now on the developer's changes wait for review, and the review page no longer shows the listing.
        $this->put(route('developer.items.update', $item), ['name' => 'Paper Two', 'summary' => 'A calm light theme.'])->assertSessionHas('status');
        $this->assertSame('Paper', $item->fresh()->name);
        $this->assertSame('Paper Two', $item->fresh()->pending_listing['name']);
    }

    /**
     * A developer (signed in as a client) sends a new free theme "calm" for review.
     *
     * @param  array<string, mixed>  $extra
     */
    private function submitItem(array $extra = []): MarketplaceItem
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => 'Las', 'company_name' => null]);
        $this->actingAs($client, 'web')->post(route('developer.join.store'), ['name' => 'Raz Studio', 'agree' => '1'])->assertRedirect(route('developer.dashboard'));

        $path = $this->dir.'/calm.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('theme.json', (string) json_encode(['slug' => 'calm', 'name' => 'Paper', 'version' => '1.0.0', 'requires' => '>=0.3.0']));
        $zip->addFromString('views/layouts/app.blade.php', '<html></html>');
        $zip->close();

        $this->post(route('developer.items.store'), $extra + [
            'name' => 'Paper', 'slug' => 'calm', 'type' => 'theme', 'summary' => 'A calm light theme.',
            'demo_url' => 'https://demo.example.org/calm', 'own_code' => '1',
            'package' => new UploadedFile($path, 'calm.zip', 'application/zip', null, true),
        ])->assertRedirect(route('developer.items.show', 'calm'));

        return MarketplaceItem::query()->sole();
    }
}
