<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Knows whether the web installer has finished on this copy of Nuvabill.
 */
class Installation
{
    public static function isInstalled(): bool
    {
        $override = config('nuvabill.installed');

        if ($override !== null && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOL);
        }

        if (is_file(self::lockPath())) {
            return true;
        }

        // The lock file can get lost (a database restore on a fresh upload, a new server, a storage
        // folder that was not copied). A database that already has staff accounts is installed, so the
        // installer must stay closed: put the lock back.
        if (self::databaseHasStaff()) {
            rescue(fn () => self::markInstalled(), report: false);

            return true;
        }

        return false;
    }

    /**
     * Whether the database in .env already has a staff account.
     */
    public static function databaseHasStaff(): bool
    {
        try {
            return Schema::hasTable('admins') && DB::table('admins')->exists();
        } catch (Throwable) {
            return false;
        }
    }

    public static function lockPath(): string
    {
        return storage_path('app/installed.json');
    }

    public static function markInstalled(): void
    {
        file_put_contents(self::lockPath(), json_encode([
            'version' => config('nuvabill.version'),
            'installed_at' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * A fresh upload has no .env or app key yet. Create them before anything needs encryption,
     * so the installer's first page can load. Demo sites skip the installer, so they get a key too.
     */
    public static function ensureAppKey(): void
    {
        if (filled(config('app.key')) || (self::isInstalled() && ! Demo::isEnabled())) {
            return;
        }

        $key = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));

        try {
            $env = new EnvFile(base_path('.env'));
            $env->ensureExists(base_path('.env.example'));
            $env->set(['APP_KEY' => $key]);
        } catch (Throwable) {
            // The installer's requirement check explains how to make the folder writable.
        }

        config(['app.key' => $key]);
    }
}
