<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\ExtensionManager;
use App\Marketplace\LicenseChecker;
use App\Marketplace\PackageSignature;
use App\Models\MarketplaceInstall;
use App\Support\Themes;
use App\Updates\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class MarketplaceClientTest extends TestCase
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

    public function test_the_marketplace_shows_the_catalog_and_what_is_installed(): void
    {
        Http::fake([self::STORE.'/api/marketplace/v1/catalog*' => Http::response(['items' => [
            $this->item('aurora', 'theme', ['name' => 'Aurora', 'price' => 5900, 'featured' => true, 'category' => 'Client themes']),
            $this->item('chat-alerts', 'addon', ['name' => 'Team chat alerts', 'category' => 'Notifications']),
        ]])]);

        $this->get(route('admin.marketplace.index'))->assertOk()->assertSee('Aurora')->assertSee('$59.00')->assertSee('Team chat alerts')->assertSee('Featured client theme');
        $this->get(route('admin.marketplace.index', ['tab' => 'themes']))->assertOk()->assertSee('Aurora')->assertDontSee('Team chat alerts');
        $this->get(route('admin.marketplace.index', ['tab' => 'installed']))->assertOk()->assertSee('Nova')->assertDontSee('Aurora');
        $this->get(route('admin.marketplace.show', 'aurora'))->assertOk()->assertSee('License key')->assertSee('Changes client area pages');
    }

    public function test_a_signed_extension_installs_in_one_click_and_can_be_removed(): void
    {
        $zip = $this->package('chat-alerts', '1.0.0', 'extension.json', [
            'slug' => 'chat-alerts', 'type' => 'addon', 'name' => 'Team chat alerts', 'version' => '1.0.0',
            'namespace' => 'Vendor\\ChatAlerts\\', 'class' => 'Vendor\\ChatAlerts\\ChatAlerts', 'permissions' => ['events', 'http:api.telegram.org'],
        ], ['src/ChatAlerts.php' => "<?php\nnamespace Vendor\\ChatAlerts;\nclass ChatAlerts extends \\App\\Extensions\\Addons\\Addon {}\n"]);

        $this->fakeStore('chat-alerts', 'addon', '1.0.0', $zip);

        $this->post(route('admin.marketplace.install', 'chat-alerts'))->assertRedirect(route('admin.marketplace.show', 'chat-alerts'));

        $this->assertFileExists($this->dir.'/extensions/addons/chat-alerts/src/ChatAlerts.php');
        $this->assertSame('1.0.0', MarketplaceInstall::query()->sole()->version);
        $this->assertNotNull(app(ExtensionManager::class)->find('chat-alerts'));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::STORE.'/api/marketplace/v1/download' && $request['slug'] === 'chat-alerts');

        $this->delete(route('admin.marketplace.destroy', 'chat-alerts'))->assertRedirect();
        $this->assertDirectoryDoesNotExist($this->dir.'/extensions/addons/chat-alerts');
        $this->assertSame(0, MarketplaceInstall::query()->count());
    }

    public function test_a_package_with_a_bad_signature_is_refused(): void
    {
        $zip = $this->package('paper', '1.0.0', 'theme.json', ['slug' => 'paper', 'name' => 'Paper', 'version' => '1.0.0'], ['views/layouts/app.blade.php' => 'x']);
        $attacker = Signature::generateKeyPair();

        $this->fakeStore('paper', 'theme', '1.0.0', $zip, PackageSignature::sign('paper', '1.0.0', hash('sha256', $zip), $attacker['secret']));

        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionHas('error', 'The package failed the security check and was not installed.');
        $this->assertDirectoryDoesNotExist($this->dir.'/themes/paper');
    }

    public function test_a_package_cannot_write_outside_its_folder(): void
    {
        $zip = $this->package('paper', '1.0.0', 'theme.json', ['slug' => 'paper', 'name' => 'Paper', 'version' => '1.0.0'], ['../../escape.php' => '<?php']);
        $this->fakeStore('paper', 'theme', '1.0.0', $zip);

        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionHas('error');
        $this->assertFileDoesNotExist($this->dir.'/escape.php');
    }

    public function test_a_theme_installs_and_can_be_previewed_then_switched_on(): void
    {
        $zip = $this->package('paper', '1.0.0', 'theme.json', ['slug' => 'paper', 'name' => 'Paper', 'version' => '1.0.0', 'paid' => true], [
            'views/partials/brand-style.blade.php' => '<meta name="theme" content="paper">',
        ]);
        $this->fakeStore('paper', 'theme', '1.0.0', $zip);

        $this->post(route('admin.marketplace.install', 'paper'), ['license_key' => 'NVB-TEST-KEY1-0001'])->assertSessionHasNoErrors();

        $this->get(route('preview.start', ['theme', 'paper']))->assertRedirect(route('store.index'));
        $this->get(route('store.index'))->assertSee('<meta name="theme" content="paper">', false)->assertSee('You are previewing Paper');

        $this->post(route('admin.marketplace.activate', 'paper'))->assertSessionHas('status');
        $this->assertSame('paper', setting('theme.active'));
        $this->assertSame('NVB-TEST-KEY1-0001', MarketplaceInstall::query()->sole()->license_key);
    }

    public function test_a_hand_copied_paid_theme_warns_until_it_is_installed_from_the_marketplace(): void
    {
        File::ensureDirectoryExists($this->dir.'/themes/aurora');
        File::put($this->dir.'/themes/aurora/theme.json', json_encode(['slug' => 'aurora', 'name' => 'Aurora', 'version' => '1.0.0', 'paid' => true]));
        $this->get(route('admin.dashboard'))->assertSee('Aurora is unlicensed.');

        $zip = $this->package('aurora', '1.0.0', 'theme.json', ['slug' => 'aurora', 'name' => 'Aurora', 'version' => '1.0.0', 'paid' => true], [
            'views/partials/brand-style.blade.php' => '<meta name="theme" content="aurora">',
        ]);
        $this->fakeStore('aurora', 'theme', '1.0.0', $zip);
        $this->post(route('admin.marketplace.install', 'aurora'), ['license_key' => 'NVB-AURO-0000-0001'])->assertSessionHasNoErrors();

        $this->get(route('admin.dashboard'))->assertDontSee('Aurora is unlicensed.');
    }

    public function test_the_daily_check_marks_a_revoked_license_and_warns_staff(): void
    {
        $install = MarketplaceInstall::create(['slug' => 'aurora', 'type' => 'theme', 'name' => 'Aurora', 'version' => '1.0.0', 'license_key' => 'NVB-AURO-0000-0001', 'license_status' => 'valid']);

        Http::fake([self::STORE.'/api/marketplace/v1/licenses/check' => Http::response(['valid' => false, 'status' => 'revoked', 'message' => 'This license was cancelled.'])]);

        app(LicenseChecker::class)->check($install);

        $this->assertTrue($install->fresh()->hasInvalidLicense());
        $this->get(route('admin.dashboard'))->assertSee('Aurora is unlicensed.')->assertSee('This license was cancelled.');
    }

    public function test_the_last_good_license_answer_counts_while_the_store_is_down(): void
    {
        $install = MarketplaceInstall::create(['slug' => 'aurora', 'type' => 'theme', 'name' => 'Aurora', 'version' => '1.0.0', 'license_key' => 'K', 'license_status' => 'valid', 'license_checked_at' => now()->subDays(3)]);
        Http::fake([self::STORE.'/*' => Http::response('down', 503)]);

        app(LicenseChecker::class)->check($install);

        $this->assertSame(MarketplaceInstall::LICENSE_VALID, $install->fresh()->license_status);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function item(string $slug, string $type, array $overrides = []): array
    {
        return $overrides + [
            'slug' => $slug, 'type' => $type, 'name' => ucfirst($slug), 'summary' => 'A test item.', 'version' => '1.0.0',
            'price' => 0, 'currency' => 'USD', 'developer' => ['name' => 'Nuvabill', 'verified' => true],
            'permissions' => $type === 'theme' ? ['client-area'] : ['events'], 'compatible' => true,
        ];
    }

    private function fakeStore(string $slug, string $type, string $version, string $zip, ?string $signature = null): void
    {
        $sha = hash('sha256', $zip);

        Http::fake([
            self::STORE.'/api/marketplace/v1/catalog*' => Http::response(['items' => [$this->item($slug, $type, ['version' => $version])]]),
            self::STORE.'/api/marketplace/v1/download' => Http::response([
                'slug' => $slug, 'type' => $type, 'version' => $version, 'url' => self::STORE.'/files/'.$slug.'.zip', 'sha256' => $sha,
                'signature' => $signature ?? PackageSignature::sign($slug, $version, $sha, $this->keys['secret']),
            ]),
            self::STORE.'/files/*' => Http::response($zip),
        ]);
    }

    /**
     * Build a package zip in memory and return its bytes.
     *
     * @param  array<string, mixed>  $manifest
     * @param  array<string, string>  $files
     */
    private function package(string $slug, string $version, string $manifestFile, array $manifest, array $files): string
    {
        $path = $this->dir.'/'.$slug.'-'.$version.'.zip';
        File::ensureDirectoryExists($this->dir);
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString($manifestFile, (string) json_encode($manifest));

        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return (string) file_get_contents($path);
    }
}
