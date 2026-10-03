<?php

namespace Tests;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Normalizer;
use Pdo\Sqlite;

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
