<?php

namespace Tests\Feature;

use App\Updates\Backup;
use App\Updates\Signature;
use App\Updates\UpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuvabill-test-'.uniqid();
        mkdir($this->workDir);
    }

    protected function tearDown(): void
    {
        $this->app['files']->deleteDirectory($this->workDir);

        parent::tearDown();
    }

    public function test_signatures_only_verify_for_the_same_file_and_version(): void
    {
        $keys = Signature::generateKeyPair();
        $zip = $this->workDir.'/release.zip';
        file_put_contents($zip, 'release contents');

        $signature = Signature::sign('0.2.0', $zip, $keys['secret']);

        $this->assertTrue(Signature::verify('0.2.0', $zip, $signature, $keys['public']));
        $this->assertFalse(Signature::verify('0.3.0', $zip, $signature, $keys['public']), 'An old zip cannot pose as a newer version.');

        file_put_contents($zip, 'tampered contents');
        $this->assertFalse(Signature::verify('0.2.0', $zip, $signature, $keys['public']));

        $other = Signature::generateKeyPair();
        $this->assertFalse(Signature::verify('0.2.0', $zip, Signature::sign('0.2.0', $zip, $other['secret']), $keys['public']));
    }

    public function test_check_finds_the_newest_stable_release_with_signed_assets(): void
    {
        config(['nuvabill.version' => '0.1.0']);

        Http::fake(['api.github.com/repos/meroxis/nuvabill/releases*' => Http::response([
            $this->release('v0.3.0-beta.1', prerelease: true),
            $this->release('v0.2.1', notes: "Fixes\n\n[security] Login rate limit"),
            $this->release('v0.2.0'),
            ['tag_name' => 'v0.9.0', 'draft' => false, 'prerelease' => false, 'assets' => []],
            ['tag_name' => 'v1.0.0', 'draft' => true, 'prerelease' => false, 'assets' => []],
        ])]);

        $release = app(UpdateManager::class)->check();

        $this->assertSame('0.2.1', $release->version);
        $this->assertTrue($release->isSecurity);
        $this->assertSame('0.2.1', app(UpdateManager::class)->available()->version);

        $this->setSettings(['updates.channel' => 'beta']);
        $this->assertSame('0.3.0-beta.1', app(UpdateManager::class)->check()->version);
    }

    public function test_check_reports_nothing_when_up_to_date(): void
    {
        config(['nuvabill.version' => '0.2.1']);
        Http::fake(['api.github.com/*' => Http::response([$this->release('v0.2.1')])]);

        $this->assertNull(app(UpdateManager::class)->check());
        $this->assertNull(app(UpdateManager::class)->available());
    }

    public function test_an_update_with_a_bad_signature_is_refused_and_nothing_changes(): void
    {
        config(['nuvabill.version' => '0.1.0']);
        $trusted = Signature::generateKeyPair();
        $attacker = Signature::generateKeyPair();
        config(['nuvabill.updates.public_key' => $trusted['public']]);

        $zip = $this->workDir.'/nuvabill-0.2.0.zip';
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('routes/evil.php', '<?php // replaced');
        $archive->close();

        Http::fake([
            'api.github.com/*' => Http::response([$this->release('v0.2.0')]),
            'downloads.example.test/nuvabill-0.2.0.zip' => Http::response((string) file_get_contents($zip)),
            'downloads.example.test/nuvabill-0.2.0.zip.sig' => Http::response(Signature::sign('0.2.0', $zip, $attacker['secret'])),
        ]);

        $updates = app(UpdateManager::class);
        $release = $updates->check();

        try {
            $updates->install($release);
            $this->fail('A wrongly signed update must not install.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('security check', $exception->getMessage());
        }

        $this->assertFileDoesNotExist(base_path('routes/evil.php'));
        $this->assertFalse($updates->hasPendingFinish());
        $this->assertDatabaseHas('activity_logs', ['action' => 'update.rejected']);
    }

    public function test_a_second_update_cannot_start_while_one_is_being_installed(): void
    {
        config(['nuvabill.version' => '0.1.0', 'nuvabill.updates.public_key' => Signature::generateKeyPair()['public']]);
        Http::fake(['api.github.com/*' => Http::response([$this->release('v0.2.0')])]);
        $updates = app(UpdateManager::class);
        $release = $updates->check();

        if (! is_dir(storage_path('app/updates'))) {
            mkdir(storage_path('app/updates'), 0755, true);
        }

        $running = fopen(storage_path('app/updates/install.lock'), 'c');
        flock($running, LOCK_EX);

        try {
            $updates->install($release);
            $this->fail('A second update must not start.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Another update is being installed', $exception->getMessage());
        } finally {
            flock($running, LOCK_UN);
            fclose($running);
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'downloads.example.test'));
    }

    public function test_zip_entries_cannot_escape_the_target_or_touch_protected_files(): void
    {
        $zipPath = $this->workDir.'/bad.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('../escape.txt', 'x');
        $zip->addFromString('.env', 'APP_KEY=stolen');
        $zip->addFromString('storage/app/x.txt', 'x');
        $zip->addFromString('app/Ok.php', 'ok');
        $zip->close();

        $target = $this->workDir.'/site';
        mkdir($target);
        $zip->open($zipPath);

        $results = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $results[$zip->getNameIndex($i)] = Backup::extractEntry($zip, $i, $target);
        }
        $zip->close();

        $this->assertSame(['../escape.txt' => false, '.env' => false, 'storage/app/x.txt' => false, 'app/Ok.php' => true], $results);
        $this->assertFileDoesNotExist($this->workDir.'/escape.txt');
        $this->assertFileExists($target.'/app/Ok.php');
    }

    public function test_the_updates_page_shows_an_available_release(): void
    {
        config(['nuvabill.version' => '0.1.0']);
        $this->setSettings(['updates.latest' => [
            'version' => '0.2.0',
            'notes' => '## New\n- Automation builder',
            'zip_url' => 'https://downloads.example.test/nuvabill-0.2.0.zip',
            'signature_url' => 'https://downloads.example.test/nuvabill-0.2.0.zip.sig',
            'published_at' => '2026-10-01T10:00:00Z',
            'security' => false,
            'prerelease' => false,
        ]]);

        $this->signInAdmin();

        $this->get(route('admin.updates.index'))->assertOk()->assertSee('v0.2.0')->assertSee('Update now');
        $this->get(route('admin.dashboard'))->assertOk();
    }

    /**
     * @return array<string, mixed>
     */
    private function release(string $tag, bool $prerelease = false, string $notes = 'Notes'): array
    {
        $version = ltrim($tag, 'v');

        return [
            'tag_name' => $tag,
            'draft' => false,
            'prerelease' => $prerelease,
            'body' => $notes,
            'published_at' => '2026-10-01T10:00:00Z',
            'assets' => [
                ['name' => "nuvabill-{$version}.zip", 'browser_download_url' => "https://downloads.example.test/nuvabill-{$version}.zip", 'size' => 1000],
                ['name' => "nuvabill-{$version}.zip.sig", 'browser_download_url' => "https://downloads.example.test/nuvabill-{$version}.zip.sig", 'size' => 88],
            ],
        ];
    }
}
