<?php

namespace Tests\Feature;

use App\Updates\Backup;
use App\Updates\Release;
use App\Updates\Signature;
use App\Updates\UpdateManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
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
        @unlink(storage_path('app/updates/pending.json'));
        @unlink(storage_path('app/updates/installing.json'));
        @unlink(storage_path('app/updates/nuvabill-0.2.0.zip'));

        if ($this->app->isDownForMaintenance()) {
            Artisan::call('up');
        }

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

    public function test_the_notes_show_every_skipped_version_without_github_links(): void
    {
        config(['nuvabill.version' => '0.3.1']);

        Http::fake(['api.github.com/*' => Http::response([
            $this->release('v0.3.3', notes: "- Safer updates\n\n**Full Changelog**: https://github.com/meroxis/nuvabill/compare/v0.3.2...v0.3.3"),
            $this->release('v0.3.2', notes: "## What's Changed\n- Clearer payment errors"),
            $this->release('v0.3.1', notes: '- Already installed'),
        ])]);

        $release = app(UpdateManager::class)->check();

        $this->assertSame('0.3.3', $release->version);
        $this->assertSame("### 0.3.3\n\n- Safer updates\n\n### 0.3.2\n\n- Clearer payment errors", $release->notes);
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
        } catch (RuntimeException $exception) {
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
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Another update is being installed', $exception->getMessage());
        } finally {
            flock($running, LOCK_UN);
            fclose($running);
        }

        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'downloads.example.test'));
    }

    public function test_the_backup_before_an_update_is_taken_in_maintenance_mode(): void
    {
        $backup = $this->fakeBackup();
        [$updates, $release] = $this->signedRelease('0.2.0');

        $updates->install($release);

        $this->assertTrue($backup->downWhileBackingUp, 'Payments made while the backup is taken would be lost if it is put back.');
        $this->assertTrue($this->app->isDownForMaintenance());
        $this->assertTrue($updates->hasPendingFinish());
        $this->assertFileDoesNotExist(storage_path('app/updates/installing.json'));
        $this->assertFileDoesNotExist(storage_path('app/updates/nuvabill-0.2.0.zip'));
    }

    public function test_a_failed_backup_before_an_update_changes_nothing(): void
    {
        $backup = $this->fakeBackup(fails: true);
        [$updates, $release] = $this->signedRelease('0.2.0');

        try {
            $updates->install($release);
            $this->fail('An update must not go on without its backup.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('backup before the update failed', $exception->getMessage());
        }

        $this->assertTrue($backup->downWhileBackingUp);
        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertFalse($updates->hasPendingFinish());
        $this->assertFileDoesNotExist(storage_path('app/updates/installing.json'));
        $this->assertFileDoesNotExist(storage_path('app/updates/nuvabill-0.2.0.zip'));
    }

    public function test_an_update_cannot_be_finished_twice_at_the_same_time(): void
    {
        $this->writeUpdateFile('pending.json', ['from' => '0.1.0', 'to' => '0.2.0', 'backup' => $this->workDir.'/before.zip', 'started_at' => now()->toIso8601String()]);
        $running = $this->holdUpdateLock();

        try {
            app(UpdateManager::class)->finish();
            $this->fail('A second finish must not migrate or restore under a running one.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('still running', $exception->getMessage());
        } finally {
            flock($running, LOCK_UN);
            fclose($running);
        }

        $this->assertFileExists(storage_path('app/updates/pending.json'));
        $this->assertDatabaseMissing('activity_logs', ['action' => 'update.installed']);
        $this->assertDatabaseMissing('activity_logs', ['action' => 'update.failed']);
    }

    public function test_the_scheduler_rolls_back_an_install_that_stopped_half_way(): void
    {
        $backup = $this->fakeBackup();
        file_put_contents($this->workDir.'/before.zip', 'backup');
        $this->writeUpdateFile('installing.json', ['from' => '0.1.0', 'to' => '0.2.0', 'backup' => $this->workDir.'/before.zip', 'started_at' => now()->toIso8601String()]);
        Artisan::call('down');

        $this->artisan('nuvabill:update', ['--finish-pending' => true])->assertFailed();

        // Only the files: the database was not changed yet, and orders made since stay.
        $this->assertSame([[$this->workDir.'/before.zip', false]], $backup->restored);
        $this->assertFalse($this->app->isDownForMaintenance());
        $this->assertFalse(app(UpdateManager::class)->hasPendingFinish());
        $this->assertFileDoesNotExist(storage_path('app/updates/installing.json'));
        $this->assertDatabaseHas('activity_logs', ['action' => 'update.failed']);
    }

    public function test_the_scheduler_leaves_an_install_that_is_still_running_alone(): void
    {
        $backup = $this->fakeBackup();
        $this->writeUpdateFile('installing.json', ['from' => '0.1.0', 'to' => '0.2.0', 'backup' => null, 'started_at' => now()->toIso8601String()]);
        $running = $this->holdUpdateLock();

        try {
            $this->assertFalse(app(UpdateManager::class)->hasPendingFinish());
            $this->artisan('nuvabill:update', ['--finish-pending' => true])->assertSuccessful();
        } finally {
            flock($running, LOCK_UN);
            fclose($running);
        }

        $this->assertSame([], $backup->restored);
        $this->assertFileExists(storage_path('app/updates/installing.json'));
    }

    public function test_restoring_a_large_database_backup_does_not_load_it_into_memory(): void
    {
        DB::statement('create table probe (id integer, body text)');
        $rows = 60_000;
        $body = str_repeat('x', 500);

        $sql = $this->workDir.'/dump.sql';
        $handle = fopen($sql, 'wb');

        for ($id = 1; $id <= $rows; $id++) {
            fwrite($handle, "INSERT INTO `probe` VALUES ({$id},'{$body}');\n");
        }

        fclose($handle);

        $zipPath = $this->workDir.'/before.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFile($sql, '__nuvabill_database.sql');
        $zip->setCompressionName('__nuvabill_database.sql', ZipArchive::CM_STORE);
        $zip->close();
        mkdir($this->workDir.'/site');

        memory_reset_peak_usage();
        $before = memory_get_usage();

        (new Backup($this->workDir.'/site', $this->workDir.'/backups'))->restore($zipPath);

        // The dump is about 30 MB; it is read line by line, not all at once.
        $this->assertLessThan(8 * 1024 * 1024, memory_get_peak_usage() - $before);
        $this->assertSame($rows, DB::table('probe')->count());
    }

    public function test_a_new_update_backup_removes_database_dumps_left_by_a_stopped_one(): void
    {
        $folder = $this->workDir.'/backups';
        mkdir($folder);
        touch($old = $folder.'/.dump-0a1b2c3d4e5f.sql', time() - 7200);
        touch($fresh = $folder.'/.dump-6a7b8c9d0e1f.sql');
        mkdir($site = $this->workDir.'/site');
        file_put_contents($site.'/artisan', '<?php // the site');

        $file = (new Backup($site, $folder))->create('before-0.2.0');

        $this->assertFileExists($file);
        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($fresh, 'A backup running right now may still be writing its dump.');
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
     * A correctly signed release that writes no real file when installed: its only entry is in
     * storage, which updates never touch.
     *
     * @return array{UpdateManager, Release}
     */
    private function signedRelease(string $version): array
    {
        $keys = Signature::generateKeyPair();
        config(['nuvabill.version' => '0.1.0', 'nuvabill.updates.public_key' => $keys['public']]);

        $zip = $this->workDir."/nuvabill-{$version}.zip";
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE);
        $archive->addFromString('storage/app/never-written.txt', 'x');
        $archive->close();

        Http::fake([
            'api.github.com/*' => Http::response([$this->release('v'.$version)]),
            "downloads.example.test/nuvabill-{$version}.zip" => Http::response((string) file_get_contents($zip)),
            "downloads.example.test/nuvabill-{$version}.zip.sig" => Http::response(Signature::sign($version, $zip, $keys['secret'])),
        ]);

        $updates = app(UpdateManager::class);

        return [$updates, $updates->check()];
    }

    /**
     * A backup that only notes what it was asked to do, so tests never zip or restore the real site.
     */
    private function fakeBackup(bool $fails = false): Backup
    {
        $backup = new class($this->workDir, $this->workDir.'/backups') extends Backup
        {
            public ?bool $downWhileBackingUp = null;

            public bool $fails = false;

            /** @var list<array{string, bool}> */
            public array $restored = [];

            public function create(string $label): string
            {
                $this->downWhileBackingUp = app()->isDownForMaintenance();

                if ($this->fails) {
                    throw new RuntimeException('The disk is full.');
                }

                return $label.'.zip';
            }

            public function restore(string $file, bool $includeDatabase = true): void
            {
                $this->restored[] = [$file, $includeDatabase];
            }
        };

        $backup->fails = $fails;
        $this->app->bind(Backup::class, fn () => $backup);

        return $backup;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeUpdateFile(string $name, array $data): void
    {
        if (! is_dir(storage_path('app/updates'))) {
            mkdir(storage_path('app/updates'), 0755, true);
        }

        file_put_contents(storage_path('app/updates/'.$name), json_encode($data));
    }

    /**
     * The lock a running install or finish holds.
     *
     * @return resource
     */
    private function holdUpdateLock()
    {
        $running = fopen(storage_path('app/updates/install.lock'), 'c');
        flock($running, LOCK_EX);

        return $running;
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
