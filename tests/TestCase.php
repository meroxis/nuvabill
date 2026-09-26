<?php

namespace Tests;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Roles, departments and email templates exist in every test that refreshes the database.
     */
    protected string $seeder = DefaultDataSeeder::class;

    protected bool $seed = true;

    protected function signInAdmin(?Admin $admin = null): Admin
    {
        $admin ??= Admin::factory()->create();
        $this->actingAs($admin, 'admin');

        return $admin;
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
