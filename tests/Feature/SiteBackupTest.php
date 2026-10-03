<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\SiteBackup;
use App\Updates\Backup;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Group;
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

        $this->assertSame([$this->databaseEntry(), 'RESTORE.txt'], $this->names($zip));
        $this->assertStringStartsWith(SiteBackup::prefix('database'), basename($path));
        $this->assertSame('Raz', $this->adminNameIn($zip));
    }

    public function test_a_password_encrypts_everything_but_the_restore_notes(): void
    {
        $this->assertTrue(SiteBackup::canEncrypt(), 'The zip extension here cannot encrypt.');

        $zip = $this->open(app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE, 'long-backup-password'));

        $this->assertFalse($zip->getFromName($this->databaseEntry()));
        $this->assertStringContainsString('7-Zip', (string) $zip->getFromName('RESTORE.txt'));

        $zip->setPassword('long-backup-password');
        $this->assertNotFalse($zip->getFromName($this->databaseEntry()));
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

    public function test_database_copies_left_by_a_stopped_backup_are_removed(): void
    {
        mkdir($this->folder, 0755, true);
        touch($old = $this->folder.DIRECTORY_SEPARATOR.'.database-0a1b2c3d4e5f', time() - 7200);
        touch($fresh = $this->folder.DIRECTORY_SEPARATOR.'.database-6a7b8c9d0e1f');

        $path = app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE);

        $this->assertFileExists($path);
        $this->assertFileDoesNotExist($old, 'A plain-text copy of the database must not stay behind.');
        $this->assertFileExists($fresh, 'A backup running right now may still be writing its copy.');
    }

    /**
     * Runs only against MySQL or MariaDB: rows go to the file one by one, so a database
     * much larger than the memory limit still backs up and restores.
     */
    #[Group('mysql')]
    public function test_a_large_mysql_database_is_written_row_by_row(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs a MySQL or MariaDB test database.');
        }

        $rows = 100_000;
        $body = str_repeat('x', 1000);
        Schema::create('backup_probe', function (Blueprint $table): void {
            $table->unsignedInteger('id')->primary();
            $table->text('body');
        });

        try {
            foreach (array_chunk(range(1, $rows), 1000) as $chunk) {
                DB::table('backup_probe')->insert(array_map(fn (int $id): array => ['id' => $id, 'body' => $body], $chunk));
            }

            $site = $this->folder.DIRECTORY_SEPARATOR.'site';
            $updates = $this->folder.DIRECTORY_SEPARATOR.'updates';
            File::ensureDirectoryExists($site);
            $limit = (string) ini_get('memory_limit');
            ini_set('memory_limit', (string) (memory_get_usage(true) + 48 * 1024 * 1024));

            try {
                $path = app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE);
                $before = (new Backup($site, $updates))->create('before-test');
            } finally {
                ini_set('memory_limit', $limit);
            }

            $zip = $this->open($path);
            $stream = $zip->getStream('database.sql');
            $inserts = 0;

            while (($line = fgets($stream)) !== false) {
                $inserts += str_starts_with($line, 'INSERT INTO `backup_probe`') ? 1 : 0;
            }

            fclose($stream);
            $this->assertSame($rows, $inserts);

            DB::table('backup_probe')->delete();
            (new Backup($site, $updates))->restore($before);

            $this->assertSame($rows, DB::table('backup_probe')->count());
            $this->assertSame([], glob($updates.DIRECTORY_SEPARATOR.'.dump-*') ?: []);
        } finally {
            Schema::dropIfExists('backup_probe');
        }
    }

    /**
     * Runs only against MySQL or MariaDB: a table a failed update made is gone after the restore,
     * so the next try of that update does not stop at "table already exists" again.
     */
    #[Group('mysql')]
    public function test_a_mysql_restore_removes_tables_a_failed_update_made(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Needs a MySQL or MariaDB test database.');
        }

        $site = $this->folder.DIRECTORY_SEPARATOR.'site';
        $updates = $this->folder.DIRECTORY_SEPARATOR.'updates';
        File::ensureDirectoryExists($site);
        $tables = Schema::getTableListing(schemaQualified: false);

        try {
            $before = (new Backup($site, $updates))->create('before-test');

            Schema::create('made_by_failed_update', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('client_id')->nullable()->constrained();
            });

            (new Backup($site, $updates))->restore($before);

            $this->assertFalse(Schema::hasTable('made_by_failed_update'));
            $this->assertEqualsCanonicalizing($tables, Schema::getTableListing(schemaQualified: false), 'Every table of the backup is back.');
        } finally {
            Schema::dropIfExists('made_by_failed_update');
        }
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

    /**
     * The database file in a backup: a copy of the SQLite file, or a dump of MySQL or MariaDB.
     */
    private function databaseEntry(): string
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'database.sql' : 'database.sqlite';
    }

    private function adminNameIn(ZipArchive $zip): string
    {
        if ($this->databaseEntry() === 'database.sql') {
            // One INSERT per row, with the values in the column order of the table.
            preg_match('/^INSERT INTO `admins` VALUES \((.*)\);$/m', (string) $zip->getFromName('database.sql'), $match);
            $values = str_getcsv($match[1] ?? '', ',', "'", '\\');

            return (string) ($values[array_search('name', Schema::getColumnListing('admins'), true)] ?? '');
        }

        $copy = $this->folder.DIRECTORY_SEPARATOR.'restored-'.Str::random(6).'.sqlite';
        file_put_contents($copy, $zip->getFromName('database.sqlite'));
        $pdo = new PDO('sqlite:'.$copy);
        $name = (string) $pdo->query('select name from admins')->fetchColumn();
        $pdo = null;

        return $name;
    }
}
