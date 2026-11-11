<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\ExtensionManager;
use App\Marketplace\PackageSignature;
use App\Models\MarketplaceInstall;
use App\Support\Themes;
use App\Updates\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

/**
 * What a site checks about the store's answer and the package before it installs anything.
 */
class PackageInstallChecksTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'https://store.example.test';

    private string $dir;

    /**
     * @var array{public: string, secret: string}
     */
    private array $keys;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/marketplace-'.uniqid());
        File::ensureDirectoryExists($this->dir.'/themes');
        File::copyDirectory(base_path('themes/nova'), $this->dir.'/themes/nova');
        $this->keys = Signature::generateKeyPair();

        config([
            'nuvabill.marketplace.url' => self::STORE,
            'nuvabill.marketplace.public_key' => $this->keys['public'],
            'nuvabill.extensions_path' => $this->dir.'/extensions',
            'nuvabill.themes_path' => $this->dir.'/themes',
            'nuvabill.orderforms_path' => $this->dir.'/orderforms',
        ]);

        $this->app->instance(ExtensionManager::class, new ExtensionManager($this->dir.'/extensions'));
        $this->app->instance(Themes::class, new Themes($this->dir.'/themes', $this->dir.'/orderforms'));
        $this->signInAdmin();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_an_installed_item_never_goes_back_to_an_older_version(): void
    {
        $this->fakeStore('paper', 'theme', '1.1.0', $this->theme('paper', '1.1.0'));
        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionMissing('error');

        $this->newStore();
        $this->fakeStore('paper', 'theme', '1.0.0', $this->theme('paper', '1.0.0'));
        $this->post(route('admin.marketplace.install', 'paper'))
            ->assertSessionHas('error', 'The marketplace sent version 1.0.0, which is older than version 1.1.0 installed here, so nothing was changed.');

        $this->assertSame('1.1.0', MarketplaceInstall::query()->sole()->version);
        $this->assertSame('1.1.0', file_get_contents($this->dir.'/themes/paper/views/version.blade.php'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'marketplace.rejected']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/files/'));

        // The same version again repairs the files, and a newer one updates them.
        foreach (['1.1.0', '1.2.0'] as $version) {
            $this->newStore();
            $this->fakeStore('paper', 'theme', $version, $this->theme('paper', $version));
            $this->post(route('admin.marketplace.install', 'paper'))->assertSessionMissing('error');

            $this->assertSame($version, MarketplaceInstall::query()->sole()->version);
            $this->assertSame($version, file_get_contents($this->dir.'/themes/paper/views/version.blade.php'));
        }
    }

    public function test_the_answer_must_be_about_the_item_staff_asked_for(): void
    {
        // A package the store really signed, but for another item.
        $this->fakeStore('midnight', 'theme', '1.0.0', $this->theme('midnight', '1.0.0'));

        $this->post(route('admin.marketplace.install', 'paper'))
            ->assertSessionHas('error', 'The marketplace sent a different package than the one you asked for, so it was not installed.');

        $this->assertSame(0, MarketplaceInstall::query()->count());
        $this->assertDirectoryDoesNotExist($this->dir.'/themes/midnight');
        $this->assertDirectoryDoesNotExist($this->dir.'/themes/paper');
        $this->assertDatabaseHas('activity_logs', ['action' => 'marketplace.rejected']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/files/'));
    }

    public function test_an_update_of_an_installed_item_is_not_answered_with_another_installed_item(): void
    {
        foreach (['paper', 'midnight'] as $slug) {
            $this->newStore();
            $this->fakeStore($slug, 'theme', '1.0.0', $this->theme($slug, '1.0.0'));
            $this->post(route('admin.marketplace.install', $slug))->assertSessionMissing('error');
        }

        $this->newStore();
        $this->fakeStore('midnight', 'theme', '1.1.0', $this->theme('midnight', '1.1.0'));
        $this->post(route('admin.marketplace.install', 'paper'))
            ->assertSessionHas('error', 'The marketplace sent a different package than the one you asked for, so it was not installed.');

        $this->assertSame(['midnight' => '1.0.0', 'paper' => '1.0.0'], MarketplaceInstall::query()->orderBy('slug')->pluck('version', 'slug')->all());
    }

    public function test_an_item_installed_before_the_store_renamed_it_still_updates(): void
    {
        $this->fakeStore('glow', 'theme', '1.0.0', $this->theme('glow', '1.0.0'));
        $this->post(route('admin.marketplace.install', 'glow'))->assertSessionMissing('error');

        // The store now lists the item as "shine". Its newest version was made before the rename, so it still says "glow".
        $this->newStore();
        $this->fakeStore('glow', 'theme', '1.1.0', $this->theme('glow', '1.1.0'));
        $this->post(route('admin.marketplace.install', 'shine'))->assertSessionMissing('error');

        $this->assertSame(['glow' => '1.1.0'], MarketplaceInstall::query()->pluck('version', 'slug')->all());

        // The old slug does not open the door to an older version either.
        $this->newStore();
        $this->fakeStore('glow', 'theme', '1.0.0', $this->theme('glow', '1.0.0'));
        $this->post(route('admin.marketplace.install', 'shine'))->assertSessionHas('error');

        $this->assertSame('1.1.0', MarketplaceInstall::query()->sole()->version);
    }

    public function test_a_package_with_a_backslash_in_a_file_name_is_not_installed(): void
    {
        $zip = $this->package(['paper/theme.json' => $this->manifest('paper', '1.0.0'), 'paper/views/app.blade.php' => '<html></html>', 'paper#extra/notes.php' => '<?php // Notes']);
        $zip = str_replace('paper#extra', 'paper\\extra', $zip);
        $this->fakeStore('paper', 'theme', '1.0.0', $zip);

        $this->post(route('admin.marketplace.install', 'paper'))
            ->assertSessionHas('error', 'The package has a file name with a backslash: paper\\extra/notes.php. Make the zip again with another zip tool.');

        $this->assertDirectoryDoesNotExist($this->dir.'/themes/paper');
        $this->assertSame(0, MarketplaceInstall::query()->count());
    }

    public function test_a_package_with_the_same_file_twice_is_not_installed(): void
    {
        $zip = $this->package(['theme.json' => $this->manifest('paper', '1.0.0'), 'views/app.blade.php' => 'first', 'views/bpp.blade.php' => 'second']);
        $zip = str_replace('views/bpp.blade.php', 'views/app.blade.php', $zip);
        $this->fakeStore('paper', 'theme', '1.0.0', $zip);

        $this->post(route('admin.marketplace.install', 'paper'))
            ->assertSessionHas('error', 'The package has the same file name more than once: views/app.blade.php');

        $this->assertDirectoryDoesNotExist($this->dir.'/themes/paper');
        $this->assertSame(0, MarketplaceInstall::query()->count());
    }

    public function test_a_file_that_is_longer_than_the_zip_says_is_not_installed(): void
    {
        // The zip lists the first file as 8 bytes long, but it holds more.
        $zip = $this->package(['views/app.blade.php' => 'The first eight bytes and then some more.', 'theme.json' => $this->manifest('paper', '1.0.0')], store: true);
        $zip = substr_replace($zip, pack('V', 8), (int) strpos($zip, "PK\x01\x02") + 24, 4);
        $this->fakeStore('paper', 'theme', '1.0.0', $zip);

        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionHas('error', 'Could not write views/app.blade.php.');

        $this->assertDirectoryDoesNotExist($this->dir.'/themes/paper');
        $this->assertSame(0, MarketplaceInstall::query()->count());
    }

    private function manifest(string $slug, string $version): string
    {
        return (string) json_encode(['slug' => $slug, 'name' => ucfirst($slug), 'version' => $version]);
    }

    /**
     * A theme package whose one view holds its version, to see which copy is on disk.
     */
    private function theme(string $slug, string $version): string
    {
        return $this->package(['theme.json' => $this->manifest($slug, $version), 'views/version.blade.php' => $version]);
    }

    /**
     * Forget the store answers faked so far, so the next fakeStore() offers another version.
     */
    private function newStore(): void
    {
        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
    }

    private function fakeStore(string $slug, string $type, string $version, string $zip): void
    {
        $sha = hash('sha256', $zip);

        Http::fake([
            self::STORE.'/api/marketplace/v1/catalog*' => Http::response(['items' => [[
                'slug' => $slug, 'type' => $type, 'name' => ucfirst($slug), 'summary' => 'A test item.', 'version' => $version,
                'price' => 0, 'currency' => 'USD', 'developer' => ['name' => 'Nuvabill', 'verified' => true], 'permissions' => ['client-area'], 'compatible' => true,
            ]]]),
            self::STORE.'/api/marketplace/v1/download' => Http::response([
                'slug' => $slug, 'type' => $type, 'version' => $version, 'url' => self::STORE.'/files/'.$slug.'.zip', 'sha256' => $sha,
                'signature' => PackageSignature::sign($slug, $version, $sha, $this->keys['secret']),
            ]),
            self::STORE.'/files/*' => Http::response($zip),
        ]);
    }

    /**
     * Build a package zip and return its bytes.
     *
     * @param  array<string, string>  $files
     */
    private function package(array $files, bool $store = false): string
    {
        $path = $this->dir.'/'.uniqid('package-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);

            if ($store) {
                $zip->setCompressionName($name, ZipArchive::CM_STORE);
            }
        }

        $zip->close();

        return (string) file_get_contents($path);
    }
}
