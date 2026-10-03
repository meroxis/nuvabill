<?php

namespace App\Console\Commands;

use App\Support\Demo;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use PDOException;
use RuntimeException;

#[Signature('nuvabill:demo-reset
    {--force : Run even when demo mode is off}
    {--wait=30 : Seconds to wait for running changes to the live database}')]
#[Description('Delete all data and fill the database with fresh demo data (demo sites only)')]
class ResetDemo extends Command
{
    /**
     * SQLite's own codes for "another connection is writing" (SQLITE_BUSY and SQLITE_LOCKED).
     */
    private const SQLITE_BUSY_CODES = [5, 6];

    public function handle(Settings $settings): int
    {
        if (! Demo::isEnabled() && ! $this->option('force')) {
            $this->components->error('This deletes all data, so it only runs when NUVABILL_DEMO=true.');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (config("database.connections.{$connection}.driver") === 'sqlite' && $database !== ':memory:') {
            try {
                $this->replaceSqliteFile($connection, $database);
            } catch (RuntimeException $exception) {
                $this->components->error($exception->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        }

        $settings->flush();

        // Visitors see a real site health result; its buttons are locked on the demo.
        rescue(fn () => $this->callSilently('nuvabill:security-check', ['--quiet-if-healthy' => true]));

        $this->components->info('The demo has fresh data.');

        return self::SUCCESS;
    }

    /**
     * Build the new database in a side file, then swap it in with one rename,
     * so visitors never see a half-filled demo. Nobody reads the side file while
     * it is built, so it skips disk syncs, which are slow on shared hosting.
     */
    private function replaceSqliteFile(string $connection, string $database): void
    {
        $next = $database.'.next';
        $original = config("database.connections.{$connection}");

        File::delete([$next, $next.'-journal']);
        File::put($next, '');

        config(["database.connections.{$connection}" => [
            ...$original,
            'database' => $next,
            'journal_mode' => 'memory',
            'synchronous' => 'off',
        ]]);
        DB::purge($connection);

        try {
            $this->call('migrate', ['--seed' => true, '--force' => true]);
        } finally {
            DB::purge($connection);
            config(["database.connections.{$connection}" => $original]);
        }

        try {
            $this->swapIn($next, $database);
        } finally {
            File::delete([$next, $next.'-journal']);
        }
    }

    /**
     * Hold the live database's write lock while the new file takes its place. A visitor's change
     * that is still being saved finishes first, so its journal (the file SQLite uses to undo a
     * half-saved change) can never be played back into the new database and break it.
     */
    private function swapIn(string $next, string $database): void
    {
        $lock = $this->lockLiveDatabase($database);

        try {
            if (PHP_OS_FAMILY === 'Windows') {
                // Windows cannot rename over an open file, so the lock goes first. A visitor
                // who still has the file open makes the rename fail instead of breaking it.
                $lock = $this->release($lock);
            }

            File::delete($database.'-journal');

            if (! File::move($next, $database)) {
                throw new RuntimeException('The new demo database could not be moved into place. The next run tries again.');
            }
        } finally {
            $this->release($lock);
        }
    }

    /**
     * An exclusive transaction on the live file: it waits for running writes and blocks new ones.
     * A missing, empty or broken live file cannot be locked and is simply replaced.
     */
    private function lockLiveDatabase(string $database): ?PDO
    {
        if (! is_file($database) || filesize($database) === 0) {
            return null;
        }

        try {
            $pdo = new PDO('sqlite:'.$database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_TIMEOUT, max(1, (int) $this->option('wait')));
            $pdo->exec('BEGIN EXCLUSIVE');

            return $pdo;
        } catch (PDOException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), self::SQLITE_BUSY_CODES, true)) {
                throw new RuntimeException('The demo database is busy, so it was not replaced. The next run tries again.', previous: $exception);
            }

            return null;
        }
    }

    private function release(?PDO $lock): null
    {
        rescue(fn () => $lock?->exec('ROLLBACK'), report: false);

        return null;
    }
}
