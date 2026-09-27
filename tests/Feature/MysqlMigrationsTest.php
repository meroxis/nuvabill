<?php

namespace Tests\Feature;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Tests\TestCase;

/**
 * The tests run on SQLite, which accepts things MySQL and MariaDB refuse. This builds the SQL
 * of every migration for MySQL, without a MySQL server, and checks the names fit its limits.
 */
class MysqlMigrationsTest extends TestCase
{
    public function test_every_migration_fits_mysql_and_mariadb_name_limits(): void
    {
        // A MySQL connection that only writes SQL: pretend mode never runs a query.
        $connection = new MySqlConnection(new PDO('sqlite::memory:'), 'nuvabill', '', ['driver' => 'mysql', 'name' => 'mysql_check']);
        config(['database.connections.mysql_check' => ['driver' => 'mysql'], 'database.default' => 'mysql_check']);
        DB::extend('mysql_check', fn () => $connection);

        $statements = [];
        $files = glob(database_path('migrations/*.php'));

        foreach ($files as $file) {
            $migration = require $file;

            foreach ($connection->pretend(fn () => $migration->up()) as $query) {
                $statements[basename($file)][] = $query['query'];
            }
        }

        $tooLong = [];

        foreach ($statements as $file => $queries) {
            foreach ($queries as $sql) {
                preg_match_all('/`([^`]+)`/', $sql, $names);

                foreach (array_unique($names[1]) as $name) {
                    if (strlen($name) > 64) {
                        $tooLong[] = "{$file}: {$name} (".strlen($name).')';
                    }
                }
            }
        }

        $this->assertGreaterThan(count($files), array_sum(array_map('count', $statements)), 'The migrations produced SQL.');
        $this->assertSame([], $tooLong, "MySQL and MariaDB allow names of up to 64 characters:\n".implode("\n", $tooLong));
    }

    public function test_the_coupon_and_addon_migration_finishes_when_run_again_after_stopping_halfway(): void
    {
        $this->artisan('migrate:fresh');

        // Where MySQL and MariaDB used to stop: the prices table without its unique index, and nothing after it.
        Schema::table('product_addon_prices', fn (Blueprint $table) => $table->dropUnique('product_addon_prices_cycle_unique'));
        Schema::drop('service_addons');

        (require database_path('migrations/2026_10_01_000001_create_coupons_and_product_addons_tables.php'))->up();

        $this->assertTrue(Schema::hasIndex('product_addon_prices', ['product_addon_id', 'currency', 'billing_cycle'], 'unique'));
        $this->assertTrue(Schema::hasTable('service_addons'));
    }
}
