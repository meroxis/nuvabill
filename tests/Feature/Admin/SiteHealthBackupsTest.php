<?php

namespace Tests\Feature\Admin;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Health\CheckResult;
use App\Health\Checks\UpkeepChecks;
use App\Health\DatabaseInspector;
use App\Health\Repairs;
use App\Health\Status;
use App\Models\Admin;
use App\Support\Activity;
use App\Support\SiteBackup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Which backups site health counts. DatabaseMigrations instead of RefreshDatabase: SQLite cannot
 * copy or optimize a database from inside the test transaction.
 */
class SiteHealthBackupsTest extends TestCase
{
    use DatabaseMigrations;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuvabill-backups-'.Str::random(8);
        $this->app->instance(SiteBackup::class, new SiteBackup($this->folder));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_safety_backups_do_not_count_as_site_backups(): void
    {
        $result = app(DatabaseInspector::class)->optimize();
        $this->assertFileExists((string) $result['backup']);
        $this->assertNull(setting('backups.last_at'));

        $this->travel(2)->seconds();
        app(Repairs::class)->run(['action' => 'db.migrate'], Admin::factory()->create(['name' => 'Mer Las']));

        $this->assertCount(2, glob($this->folder.DIRECTORY_SEPARATOR.'*.zip'));
        $this->assertNull(setting('backups.last_at'));
        $this->assertSame(Status::Warning, $this->check('upkeep.backup')->status);
    }

    public function test_a_backup_add_on_that_did_not_upload_does_not_pass(): void
    {
        $this->enableBackupAddon();

        // A local zip was made, but the upload failed.
        app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE, 'long-backup-password');
        Activity::log('backup.failed', 'Google Drive backup failed: Google did not give access');

        $check = $this->check('upkeep.backup_safe');
        $this->assertSame(Status::Warning, $check->status);
        $this->assertSame(':names has not copied a backup off this server in the last 7 days', $check->summary);

        // Once a copy is stored elsewhere, the check passes.
        app(SiteBackup::class)->record(SiteBackup::TYPE_DATABASE, encrypted: true, offsite: true);
        $this->assertSame(Status::Passed, $this->check('upkeep.backup_safe')->status);

        // An unencrypted copy elsewhere is still a warning.
        app(SiteBackup::class)->record(SiteBackup::TYPE_DATABASE, encrypted: false, offsite: true);
        $this->assertSame('The last backup was not encrypted', $this->check('upkeep.backup_safe')->summary);
    }

    public function test_an_older_backup_add_on_counts_from_its_upload_log(): void
    {
        $this->enableBackupAddon();
        app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE, 'long-backup-password');
        Activity::log('backup.uploaded', 'Google Drive backup nuvabill-database.zip uploaded (1 MB)');

        $this->assertSame(Status::Passed, $this->check('upkeep.backup_safe')->status);
    }

    private function enableBackupAddon(): void
    {
        $manifest = new ExtensionManifest('drive-backup', ExtensionManifest::TYPE_ADDON, 'Drive backup', '1.0.0', 'Test\\', 'Test\\DriveBackup', $this->folder, permissions: ['backups']);

        $this->partialMock(ExtensionManager::class, function (MockInterface $mock) use ($manifest): void {
            $mock->shouldReceive('ofType')->with(ExtensionManifest::TYPE_ADDON)->andReturn(collect(['drive-backup' => $manifest]));
            $mock->shouldReceive('isEnabled')->with('drive-backup')->andReturn(true);
        });
    }

    private function check(string $id): CheckResult
    {
        return collect(app(UpkeepChecks::class)->run())->firstWhere('id', $id);
    }
}
