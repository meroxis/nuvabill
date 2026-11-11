<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\ExtensionManager;
use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\Checks\SigningKeyChecks;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Marketplace\PackageSignature;
use App\Marketplace\Store\SigningKey;
use App\Models\MarketplaceInstall;
use App\Providers\MarketplaceStoreServiceProvider;
use App\Support\Themes;
use App\Updates\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use ZipArchive;

/**
 * The key that signs marketplace packages: sites can trust more than one key, so the marketplace
 * can change its key, and site health on the store watches where the key is kept.
 */
class SigningKeyTest extends TestCase
{
    use RefreshDatabase;

    private const STORE = 'https://store.example.test';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/signing-'.uniqid());
        File::ensureDirectoryExists($this->dir.'/themes');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);

        parent::tearDown();
    }

    public function test_a_site_installs_packages_signed_with_any_key_it_trusts(): void
    {
        File::copyDirectory(base_path('themes/nova'), $this->dir.'/themes/nova');
        $current = Signature::generateKeyPair();
        $next = Signature::generateKeyPair();
        $unknown = Signature::generateKeyPair();

        config([
            'nuvabill.marketplace.url' => self::STORE,
            'nuvabill.marketplace.public_key' => $current['public'],
            'nuvabill.extensions_path' => $this->dir.'/extensions',
            'nuvabill.themes_path' => $this->dir.'/themes',
            'nuvabill.orderforms_path' => $this->dir.'/orderforms',
        ]);
        $this->app->instance(ExtensionManager::class, new ExtensionManager($this->dir.'/extensions'));
        $this->app->instance(Themes::class, new Themes($this->dir.'/themes', $this->dir.'/orderforms'));
        $this->signInAdmin();

        // Signed with a key this copy does not know yet.
        $this->fakeStore('1.0.0', $next['secret']);
        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionHas('error', 'The package failed the security check and was not installed.');
        $this->assertSame(0, MarketplaceInstall::query()->count());

        // A release added the marketplace's next key: packages signed with either key install.
        config(['nuvabill.marketplace.public_keys' => [$next['public']]]);
        $this->fakeStore('1.0.0', $next['secret']);
        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionMissing('error');
        $this->fakeStore('1.1.0', $current['secret']);
        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionMissing('error');
        $this->assertSame('1.1.0', MarketplaceInstall::query()->sole()->version);

        // Any other key is still refused.
        $this->fakeStore('1.2.0', $unknown['secret']);
        $this->post(route('admin.marketplace.install', 'paper'))->assertSessionHas('error', 'The package failed the security check and was not installed.');
        $this->assertSame('1.1.0', MarketplaceInstall::query()->sole()->version);
    }

    public function test_site_health_on_the_store_watches_the_signing_key(): void
    {
        $this->assertNull(app(SiteHealth::class)->group(CheckGroup::SECURITY, 'signing-key'), 'Only the store has this group.');

        config(['nuvabill.marketplace.store' => true, 'nuvabill.marketplace.signing_key_path' => $this->dir.'/keys/marketplace-signing.key']);
        (new MarketplaceStoreServiceProvider($this->app))->enable();
        $this->assertInstanceOf(SigningKeyChecks::class, app(SiteHealth::class)->group(CheckGroup::SECURITY, 'signing-key'));

        $results = fn (): array => collect(app(SigningKeyChecks::class)->run())->mapWithKeys(fn (CheckResult $check): array => [$check->id => $check])->all();

        // No key yet: nothing to look at.
        $this->assertSame([Status::Skipped, Status::Skipped, Status::Skipped], array_column($results(), 'status'));

        // The key is in the Nuvabill folder, where every whole-site backup copies it.
        $public = app(SigningKey::class)->generate();
        $checks = $results();
        $this->assertSame(Status::Warning, $checks['signing_key.place']->status);
        $this->assertSame('The key is inside the Nuvabill folder', $checks['signing_key.place']->summary);
        $this->assertStringContainsString('NUVABILL_MARKETPLACE_SIGNING_KEY_PATH', $checks['signing_key.place']->advice);

        // Staff see it with the other security checks.
        Mail::fake();
        config(['app.url' => 'http://localhost']);
        app(SiteHealth::class)->run();
        $this->signInAdmin();
        $this->get(route('admin.health.index'))->assertOk()->assertSee('Marketplace signing key');
        $this->get(route('admin.health.group', ['security', 'signing-key']))->assertOk()
            ->assertSee('The marketplace signing key is kept outside the Nuvabill folder')
            ->assertSee('The key is inside the Nuvabill folder');

        // Sites must know the key, or they refuse what the store signs.
        $this->assertSame(Status::Urgent, $checks['signing_key.trusted']->status);
        config(['nuvabill.marketplace.public_keys' => [$public]]);
        $this->assertSame(Status::Passed, $results()['signing_key.trusted']->status);

        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertSame(Status::Skipped, $checks['signing_key.readers']->status);
        } else {
            $this->assertSame(Status::Passed, $checks['signing_key.readers']->status);
            chmod(app(SigningKey::class)->path(), 0644);
            clearstatcache();
            $this->assertSame(Status::Urgent, $results()['signing_key.readers']->status);
            chmod(app(SigningKey::class)->path(), 0600);
            clearstatcache();
        }

        // In the public folder anyone could fetch it.
        $this->app->usePublicPath($this->dir);
        $this->assertSame(Status::Urgent, $results()['signing_key.place']->status);

        // Outside the Nuvabill folder is the safe place. Signing works as before.
        $outside = sys_get_temp_dir().'/nuvabill-key-'.uniqid();
        File::ensureDirectoryExists($outside);
        File::move(app(SigningKey::class)->path(), $outside.'/marketplace-signing.key');
        config(['nuvabill.marketplace.signing_key_path' => $outside.'/marketplace-signing.key']);

        try {
            $this->assertSame(Status::Passed, $results()['signing_key.place']->status);
            $sha = hash('sha256', 'package');
            $this->assertTrue(PackageSignature::verify('paper', '1.0.0', $sha, PackageSignature::sign('paper', '1.0.0', $sha, app(SigningKey::class)->secret()), $public));

            // A key file that is not a key any more is reported, not skipped.
            file_put_contents($outside.'/marketplace-signing.key', 'damaged');
            $this->assertSame(Status::Urgent, $results()['signing_key.trusted']->status);
        } finally {
            File::deleteDirectory($outside);
        }
    }

    /**
     * The store offers the free theme "paper" in this version, signed with this key.
     */
    private function fakeStore(string $version, string $secretKey): void
    {
        $path = $this->dir.'/'.uniqid('package-').'.zip';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('theme.json', (string) json_encode(['slug' => 'paper', 'name' => 'Paper', 'version' => $version]));
        $zip->addFromString('views/version.blade.php', $version);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        $sha = hash('sha256', $bytes);

        Http::swap(new HttpFactory);
        Http::preventStrayRequests();
        Http::fake([
            self::STORE.'/api/marketplace/v1/catalog*' => Http::response(['items' => [[
                'slug' => 'paper', 'type' => 'theme', 'name' => 'Paper', 'summary' => 'A test item.', 'version' => $version,
                'price' => 0, 'currency' => 'USD', 'developer' => ['name' => 'Nuvabill', 'verified' => true], 'permissions' => ['client-area'], 'compatible' => true,
            ]]]),
            self::STORE.'/api/marketplace/v1/download' => Http::response([
                'slug' => 'paper', 'type' => 'theme', 'version' => $version, 'url' => self::STORE.'/files/paper.zip', 'sha256' => $sha,
                'signature' => PackageSignature::sign('paper', $version, $sha, $secretKey),
            ]),
            self::STORE.'/files/*' => Http::response($bytes),
        ]);
    }
}
