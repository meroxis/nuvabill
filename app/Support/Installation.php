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
    /**
     * Session key: the mark of the setup code this visitor typed (see setupCodeMark()).
     */
    public const SETUP_CODE_SESSION = 'install.setup_code';

    /**
     * Letters and digits that are hard to mix up when read or typed: no 0, O, 1 or I.
     */
    private const SETUP_CODE_LETTERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

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

        // The setup code has done its work. A reopened installer makes a new one.
        @unlink(self::setupCodePath());
    }

    public static function setupCodePath(): string
    {
        return storage_path('app/setup-code.txt');
    }

    /**
     * The web installer only goes on for someone who can read this site's files: it asks for the
     * code in a file that is never served to visitors. The file is made the first time the
     * installer opens. False when it could not be written.
     */
    public static function ensureSetupCode(): bool
    {
        if (self::setupCode() !== null) {
            return true;
        }

        $groups = array_map(fn (): string => implode('', array_map(
            fn (): string => self::SETUP_CODE_LETTERS[random_int(0, strlen(self::SETUP_CODE_LETTERS) - 1)],
            range(1, 4),
        )), range(1, 5));

        return @file_put_contents(self::setupCodePath(), implode('-', $groups).PHP_EOL, LOCK_EX) !== false
            && self::setupCode() !== null;
    }

    /**
     * The code in the file, or null when there is none (yet).
     */
    public static function setupCode(): ?string
    {
        $code = is_file(self::setupCodePath()) ? trim((string) @file_get_contents(self::setupCodePath())) : '';

        return preg_match('/^['.self::SETUP_CODE_LETTERS.']{4}(-['.self::SETUP_CODE_LETTERS.']{4}){4}$/D', $code) === 1 ? $code : null;
    }

    /**
     * Whether $given is the setup code. Dashes, spaces and small letters make no difference.
     */
    public static function isSetupCode(string $given): bool
    {
        $code = self::setupCode();
        $plain = fn (string $text): string => strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $text));

        return $code !== null && hash_equals($plain($code), $plain($given));
    }

    /**
     * What a session keeps once the right code was typed. It names the code without holding it,
     * so an installer that opens again later with a new code asks again.
     */
    public static function setupCodeMark(): ?string
    {
        $code = self::setupCode();

        return $code === null ? null : hash('sha256', 'nuvabill-setup-code:'.$code);
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

        $encrypt = null;

        try {
            $env = new EnvFile(base_path('.env'));
            $env->ensureExists(base_path('.env.example'));
            $env->set(['APP_KEY' => $key]);
            $encrypt = $env->get('SESSION_ENCRYPT');
        } catch (Throwable) {
            // The installer's requirement check explains how to make the folder writable.
        }

        config(['app.key' => $key]);

        // A .env made just now: this first page keeps its session the way the next pages read it.
        if ($encrypt !== null) {
            config(['session.encrypt' => filter_var($encrypt, FILTER_VALIDATE_BOOL)]);
        }
    }
}
