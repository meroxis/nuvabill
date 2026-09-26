<?php

namespace App\Console\Commands;

use App\Import\Whmcs\WhmcsImporter;
use App\Support\Activity;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('nuvabill:import-whmcs
    {--host= : WHMCS database host (empty uses the details saved in Settings → Import)}
    {--port=3306 : WHMCS database port}
    {--database= : WHMCS database name}
    {--username= : WHMCS database user}
    {--password= : WHMCS database password (or set WHMCS_DB_PASSWORD)}
    {--check : Only test the connection and count the records}')]
#[Description('Import clients, products, services, domains, invoices, payments and tickets from a WHMCS database')]
class ImportWhmcs extends Command
{
    public function handle(): int
    {
        $credentials = filled($this->option('database'))
            ? [
                'host' => $this->option('host') ?: 'localhost',
                'port' => $this->option('port'),
                'database' => $this->option('database'),
                'username' => $this->option('username'),
                'password' => $this->option('password') ?? (getenv('WHMCS_DB_PASSWORD') ?: ($this->input->isInteractive() ? (string) $this->secret('WHMCS database password') : '')),
            ]
            : (array) setting('import.whmcs');

        try {
            $importer = WhmcsImporter::connect($credentials);
            $check = $importer->check();
        } catch (Throwable $exception) {
            $this->components->error('Could not read the WHMCS database: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Connected to WHMCS '.($check['version'] ?: '(unknown version)').'.');

        if ($this->option('check')) {
            foreach (WhmcsImporter::STEPS as $step => $label) {
                $this->components->twoColumnDetail($label, number_format($check['counts'][$step]));
            }

            return self::SUCCESS;
        }

        foreach (WhmcsImporter::STEPS as $step => $label) {
            $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 500);
                $afterId = $result['last_id'];

                foreach ($totals as $key => $count) {
                    $totals[$key] = $count + $result[$key];
                }
            } while (! $result['done']);

            $this->components->twoColumnDetail($label, "added {$totals['created']}, updated {$totals['updated']}, skipped {$totals['skipped']}");
        }

        Activity::log('import.finished', 'WHMCS import finished (command line).');
        $this->components->info('Done. Enter the server passwords again in Servers and switch the servers on.');

        return self::SUCCESS;
    }
}
