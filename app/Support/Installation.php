<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
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

        return is_file(self::lockPath());
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
