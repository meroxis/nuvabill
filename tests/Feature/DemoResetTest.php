<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Client;
use App\Support\Demo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
        } finally {
            DB::purge('sqlite');
            File::delete($database);
        }
    }
}
