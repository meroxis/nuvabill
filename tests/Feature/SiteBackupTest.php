<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\SiteBackup;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PDO;
use Tests\TestCase;
use ZipArchive;

/**
 * Backups for keeping somewhere else: the whole site, or the database only. DatabaseMigrations
 * instead of RefreshDatabase: SQLite cannot copy a database from inside the test transaction.
 */
class SiteBackupTest extends TestCase
{
    use DatabaseMigrations;

    private string $folder;

    private string $probe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuvabill-backups-'.Str::random(8);
        $this->probe = storage_path('app/backup-probe-'.Str::random(6).'.txt');
        file_put_contents($this->probe, 'uploaded file');
        $this->app->instance(SiteBackup::class, new SiteBackup($this->folder));
    }

    protected function tearDown(): void
    {
        @unlink($this->probe);
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_a_whole_site_backup_has_every_file_and_the_database(): void
    {
        Admin::factory()->create(['name' => 'Mer Las']);

        $zip = $this->open(app(SiteBackup::class)->create(SiteBackup::TYPE_SITE));

        $this->assertSame('uploaded file', $zip->getFromName('site/storage/app/'.basename($this->probe)));
        $this->assertSame(file_get_contents(base_path('artisan')), $zip->getFromName('site/artisan'));
        $this->assertNotFalse($zip->getFromName('site/app/Support/SiteBackup.php'));
        $this->assertNotFalse($zip->getFromName('site/extensions/gateways/stripe/extension.json'));
        $this->assertSame(file_get_contents(base_path('.env')), $zip->getFromName('site/.env'));
        $this->assertStringContainsString('Upload everything in the "site" folder', (string) $zip->getFromName('RESTORE.txt'));

        foreach ($this->names($zip) as $name) {
            $this->assertDoesNotMatchRegularExpression('#^site/(\.git|node_modules|storage/(logs|framework)|storage/app/(backups|site-backups|updates))/#', $name);
        }

        $this->assertSame('Mer Las', $this->adminNameIn($zip));
    }

    public function test_a_database_backup_has_only_the_database(): void
    {
        Admin::factory()->create(['name' => 'Raz']);

        $zip = $this->open($path = app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE));

        $this->assertSame(['database.sqlite', 'RESTORE.txt'], $this->names($zip));
        $this->assertStringStartsWith(SiteBackup::prefix('database'), basename($path));
        $this->assertSame('Raz', $this->adminNameIn($zip));
    }

    public function test_a_password_encrypts_everything_but_the_restore_notes(): void
    {
        $this->assertTrue(SiteBackup::canEncrypt(), 'The zip extension here cannot encrypt.');

        $zip = $this->open(app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE, 'long-backup-password'));

        $this->assertFalse($zip->getFromName('database.sqlite'));
        $this->assertStringContainsString('7-Zip', (string) $zip->getFromName('RESTORE.txt'));

        $zip->setPassword('long-backup-password');
        $this->assertNotFalse($zip->getFromName('database.sqlite'));
    }

    public function test_only_the_two_newest_local_backups_of_each_type_are_kept(): void
    {
        mkdir($this->folder, 0755, true);
        touch($this->folder.DIRECTORY_SEPARATOR.SiteBackup::prefix('database').'2020-01-01-000000.zip');
        touch($this->folder.DIRECTORY_SEPARATOR.SiteBackup::prefix('database').'2020-01-02-000000.zip');
        touch($this->folder.DIRECTORY_SEPARATOR.SiteBackup::prefix('site').'2020-01-01-000000.zip');

        $newest = app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE);

        $this->assertSame([
            SiteBackup::prefix('database').'2020-01-02-000000.zip',
            basename($newest),
            SiteBackup::prefix('site').'2020-01-01-000000.zip',
        ], array_map('basename', glob($this->folder.DIRECTORY_SEPARATOR.'*.zip')));
    }

    public function test_the_backup_command_makes_either_type(): void
    {
        $this->artisan('nuvabill:backup', ['--database' => true])
            ->expectsOutputToContain('Backup made: ')
            ->assertSuccessful();

        $this->assertCount(1, glob($this->folder.DIRECTORY_SEPARATOR.SiteBackup::prefix('database').'*.zip'));
    }

    private function open(string $path): ZipArchive
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        return $zip;
    }

    /**
     * @return list<string>
     */
    private function names(ZipArchive $zip): array
    {
        return array_map(fn (int $index): string => (string) $zip->getNameIndex($index), range(0, $zip->numFiles - 1));
    }

    private function adminNameIn(ZipArchive $zip): string
    {
        $copy = $this->folder.DIRECTORY_SEPARATOR.'restored-'.Str::random(6).'.sqlite';
        file_put_contents($copy, $zip->getFromName('database.sqlite'));
        $pdo = new PDO('sqlite:'.$copy);
        $name = (string) $pdo->query('select name from admins')->fetchColumn();
        $pdo = null;

        return $name;
    }
}
