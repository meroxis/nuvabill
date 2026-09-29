<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\BrandIcon;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * The site's icon: the Nuvabill icon on every page, and the company's own icon while the site has a
 * White-label License.
 */
class BrandIconTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    protected function tearDown(): void
    {
        BrandIcon::remove();

        parent::tearDown();
    }

    public function test_every_page_shows_the_nuvabill_icon(): void
    {
        $this->get(route('store.index'))
            ->assertOk()
            ->assertSee('<link rel="icon" href="'.asset('favicon.ico').'" sizes="32x32">', false)
            ->assertSee('<link rel="icon" href="'.asset('favicon.svg').'" type="image/svg+xml">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.asset('images/app/apple-touch-icon.png').'">', false);

        $this->get(route('admin.login'))->assertOk()->assertSee(asset('favicon.svg'), false);

        $this->assertFileExists(public_path('favicon.ico'));
        $this->assertGreaterThan(0, filesize(public_path('favicon.ico')));
    }

    public function test_a_site_without_a_white_label_license_cannot_use_its_own_icon(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.settings.edit'))->assertOk()->assertSee('Your own icon needs a White-label License.');

        $this->post(route('admin.settings.icon.store'), ['icon' => UploadedFile::fake()->image('icon.png', 512, 512)])
            ->assertSessionHasErrors('icon');

        $this->assertNull(BrandIcon::name());
    }

    public function test_a_licensed_site_uses_its_own_icon_everywhere(): void
    {
        $this->license();
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->post(route('admin.settings.icon.store'), ['icon' => UploadedFile::fake()->image('icon.png', 600, 600)])
            ->assertSessionHasNoErrors();

        $url = route('brand.icon', BrandIcon::name());

        $this->get(route('store.index'))
            ->assertSee('<link rel="icon" type="image/png" href="'.$url.'">', false)
            ->assertSee('<link rel="apple-touch-icon" href="'.$url.'">', false)
            ->assertDontSee(asset('favicon.svg'), false);

        $this->get(route('admin.dashboard'))->assertSee('src="'.$url.'"', false)->assertSee('My Hosting Company');
        $this->get(route('admin.manifest'))->assertJsonPath('icons.0.src', $url)->assertJsonPath('icons.0.sizes', '600x600');

        $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_the_icon_must_be_a_big_enough_square_png(): void
    {
        $this->license();
        $this->actingAs(Admin::factory()->create(), 'admin');

        foreach ([UploadedFile::fake()->image('wide.png', 800, 400), UploadedFile::fake()->image('small.png', 256, 256), UploadedFile::fake()->image('photo.jpg', 600, 600)] as $file) {
            $this->post(route('admin.settings.icon.store'), ['icon' => $file])->assertSessionHasErrors('icon');
        }

        $this->assertNull(BrandIcon::name());
    }

    public function test_the_nuvabill_icon_comes_back_when_the_license_ends_or_the_icon_is_removed(): void
    {
        $this->license();
        $this->actingAs(Admin::factory()->create(), 'admin');
        $this->post(route('admin.settings.icon.store'), ['icon' => UploadedFile::fake()->image('icon.png', 512, 512)]);
        $url = route('brand.icon', BrandIcon::name());

        app(Settings::class)->set('license.white_label', ['valid' => false, 'status' => 'expired']);

        $this->get(route('store.index'))->assertSee(asset('favicon.svg'), false)->assertDontSee($url, false);
        $this->get($url)->assertNotFound();
        $this->assertNotNull(BrandIcon::name(), 'The icon is kept for when the license is valid again.');

        $this->license();
        $this->delete(route('admin.settings.icon.destroy'))->assertRedirect();

        $this->assertNull(BrandIcon::name());
        $this->get(route('store.index'))->assertSee(asset('favicon.svg'), false);
    }

    private function license(): void
    {
        app(Settings::class)->setMany([
            'license.white_label_key' => 'NVB-AAAA-BBBB-CCCC-DDDD',
            'license.white_label' => ['valid' => true, 'status' => 'active'],
        ]);
    }
}
