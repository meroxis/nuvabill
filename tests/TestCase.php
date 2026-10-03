<?php

namespace Tests;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Normalizer;
use PDO;
use Pdo\Sqlite;
use PDOException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles, departments and email templates exist in every test that refreshes the database.
     */
    protected string $seeder = DefaultDataSeeder::class;

    protected bool $seed = true;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        if ($this->refreshesMysqlDatabase()) {
            $this->restartMysqlIds();
        }
    }

    /**
     * SQLite gives the ids of rolled-back rows out again, so every test starts at id 1. MySQL and
     * MariaDB do not, so the counters go back to the highest id in each table (the seeded rows)
     * before the test starts. The test's own transaction, still empty here, is opened again after.
     */
    private function restartMysqlIds(): void
    {
        $pdo = DB::connection()->getPdo();

        if (! $pdo->inTransaction()) {
            return;
        }

        $pdo->rollBack();

        try {
            // MySQL 8 shows cached counters unless told not to; MariaDB always shows the live ones.
            $pdo->exec('SET SESSION information_schema_stats_expiry = 0');
        } catch (PDOException) {
            // MariaDB has no such setting.
        }

        $tables = $pdo->query("SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND AUTO_INCREMENT > 1")->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($tables as $table => $next) {
            $quoted = '`'.str_replace('`', '``', (string) $table).'`';

            try {
                if ((int) $pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM {$quoted}")->fetchColumn() < (int) $next) {
                    $pdo->exec("ALTER TABLE {$quoted} AUTO_INCREMENT = 1");
                }
            } catch (PDOException) {
                // A table whose counter is not called "id" keeps it.
            }
        }

        $pdo->beginTransaction();
    }

    private function refreshesMysqlDatabase(): bool
    {
        return in_array(RefreshDatabase::class, class_uses_recursive($this), true)
            && in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    protected function tearDown(): void
    {
        // MySQL and MariaDB save the open test transaction for good when a table is created, changed or
        // checked (CHECK TABLE). The rows of this test then stay behind, so the database is built again
        // now: the next test reads settings while it starts, before it could clean up itself.
        if (isset($this->app) && $this->refreshesMysqlDatabase() && ! DB::connection()->getPdo()->inTransaction()) {
            Artisan::call('migrate:fresh', method_exists($this, 'migrateFreshUsing') ? $this->migrateFreshUsing() : []);
        }

        parent::tearDown();
    }

    protected function signInAdmin(?Admin $admin = null): Admin
    {
        $admin ??= Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
    }

    /**
     * Make the email column of these tables compare like MySQL and MariaDB do (utf8mb4_unicode_ci,
     * which the installer sets): without letter case or accents, and with wide letters read as plain
     * ones, so "öwner@example.test" and "ｏwner@example.test" both find "owner@example.test".
     * SQLite, which the tests use, compares bytes. Call it before the rows are made.
     */
    protected function compareEmailsLikeMysql(string ...$tables): void
    {
        $fold = function (string $value): string {
            $decomposed = Normalizer::normalize($value, Normalizer::FORM_KD);

            return mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', is_string($decomposed) ? $decomposed : $value));
        };

        // A MySQL or MariaDB test database compares this way already.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }

        $pdo = DB::connection()->getPdo();
        $this->assertInstanceOf(Sqlite::class, $pdo);
        $pdo->createCollation('like_mysql', fn (string $first, string $second): int => strcmp($fold($first), $fold($second)));

        foreach ($tables as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('email')->collation('like_mysql')->change());
        }
    }

    /**
     * Turn on a payment gateway with the given settings.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function enableGateway(string $slug, array $settings = []): void
    {
        app(ExtensionManager::class)->saveSettings($slug, $settings, true);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected function setSettings(array $values): void
    {
        app(Settings::class)->setMany($values);
    }
}
