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
     * so visitors never see a half-filled demo.
     */
    private function replaceSqliteFile(string $connection, string $database): void
    {
        $next = $database.'.next';

        File::delete($next);
        File::put($next, '');

        config(["database.connections.{$connection}.database" => $next]);
        DB::purge($connection);

        $this->call('migrate', ['--seed' => true, '--force' => true]);

        DB::purge($connection);
        File::move($next, $database);
        config(["database.connections.{$connection}.database" => $database]);
    }
}
