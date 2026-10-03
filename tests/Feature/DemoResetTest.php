<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Support\Demo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class DemoResetTest extends TestCase
{
    public function test_the_reset_only_runs_on_demo_sites(): void
    {
        $this->artisan('nuvabill:demo-reset')->assertFailed();
    }

    public function test_the_reset_swaps_in_a_database_full_of_demo_data(): void
    {
        $database = storage_path('framework/testing/demo-reset.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, 'old demo data');

        config(['nuvabill.demo' => true, 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');

        try {
            $this->artisan('nuvabill:demo-reset')->assertSuccessful();

            $this->assertFileDoesNotExist($database.'.next');
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());
            $this->assertTrue(Client::query()->where('email', Demo::CLIENT_EMAIL)->exists());
            $this->assertTrue(Demo::hasData());
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        } finally {
            DB::purge('sqlite');
            File::delete($database);
        }
    }

    public function test_the_reset_waits_for_a_change_that_is_still_being_saved(): void
    {
        $database = storage_path('framework/testing/demo-reset-busy.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::delete([$database, $database.'-journal', $database.'.next']);

        // A visitor's change, half-way saved: SQLite keeps the old pages in a journal file meanwhile.
        $visitor = new PDO('sqlite:'.$database);
        $visitor->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $visitor->exec('create table visits (id integer primary key)');
        $visitor->exec('BEGIN IMMEDIATE');
        $visitor->exec('insert into visits default values');
        $this->assertFileExists($database.'-journal');

        config(['nuvabill.demo' => true, 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');

        try {
            $this->artisan('nuvabill:demo-reset', ['--wait' => 1])
                ->expectsOutputToContain('The demo database is busy')
                ->assertFailed();

            // Nothing was swapped under the visitor, so their journal still belongs to the old file.
            $this->assertFileDoesNotExist($database.'.next');
            $this->assertFileExists($database.'-journal');
            $visitor->exec('COMMIT');
            $this->assertSame(1, (int) $visitor->query('select count(*) from visits')->fetchColumn());
            $visitor = null;

            $this->artisan('nuvabill:demo-reset', ['--wait' => 1])->assertSuccessful();

            $this->assertFileDoesNotExist($database.'-journal');
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());
        } finally {
            $visitor = null;
            DB::purge('sqlite');
            File::delete([$database, $database.'-journal', $database.'.next']);
        }
    }
}
