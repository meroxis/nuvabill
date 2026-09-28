<?php

namespace App\Console\Commands;

use App\Health\DatabaseInspector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

#[Signature('nuvabill:database
    {--optimize : Rebuild tables with free space and refresh their statistics (a backup is made first)}
    {--clean : Remove old logs, ended sessions and history (never clients, invoices, payments, services or tickets)}
    {--no-backup : With --optimize: skip the backup (only when you just made one)}
    {--scheduled : Only do what is switched on in the site health settings (used by the scheduler)}')]
#[Description('Show the database size and old records, and optimize or clean up the database')]
class DatabaseUpkeep extends Command
{
    public function handle(DatabaseInspector $database): int
    {
        $clean = (bool) $this->option('clean');
        $optimize = (bool) $this->option('optimize');

        if ($this->option('scheduled')) {
            $clean = $clean && (bool) setting('database.cleanup_nightly');
            $optimize = $optimize && (bool) setting('database.optimize_weekly');

            if (! $clean && ! $optimize) {
                return self::SUCCESS;
            }
        }

        $server = $database->server();
        $totals = $database->totals();
        $this->components->info("{$server['product']} {$server['version']} · ".Number::fileSize($totals['size']).' · '.Number::fileSize($totals['free']).' can be freed');

        foreach ($database->oldRecords() as $kind) {
            $this->components->twoColumnDetail($kind['label'], number_format($kind['count']).' old records');
        }

        try {
            if ($clean) {
                $removed = array_sum($database->cleanUp());
                $this->components->info('Removed '.number_format($removed).' old records.');
            }

            if ($optimize) {
                $result = $database->optimize(backupFirst: ! $this->option('no-backup'));
                $this->components->info(($result['backup'] ? 'Backup made first. ' : '')."{$result['tables']} tables rebuilt · ".Number::fileSize($result['freed']).' freed.');
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
