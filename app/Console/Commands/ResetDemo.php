<?php

namespace App\Console\Commands;

use App\Support\Demo;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

#[Signature('nuvabill:demo-reset {--force : Run even when demo mode is off}')]
#[Description('Delete all data and fill the database with fresh demo data (demo sites only)')]
class ResetDemo extends Command
{
    public function handle(Settings $settings): int
    {
        if (! Demo::isEnabled() && ! $this->option('force')) {
            $this->components->error('This deletes all data, so it only runs when NUVABILL_DEMO=true.');

            return self::FAILURE;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (config("database.connections.{$connection}.driver") === 'sqlite' && $database !== ':memory:') {
            $this->replaceSqliteFile($connection, $database);
        } else {
            $this->call('migrate:fresh', ['--seed' => true, '--force' => true]);
        }

        $settings->flush();
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

        File::move($next, $database);
    }
}
