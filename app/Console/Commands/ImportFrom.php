<?php

namespace App\Console\Commands;

use App\Import\ImportSources;
use App\Import\Preflight;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('nuvabill:import
    {source? : whmcs, blesta, fossbilling, paymenter or clientexec (empty uses the system saved in Settings → Import)}
    {--host= : Database host (empty uses the details saved in Settings → Import)}
    {--port=3306 : Database port}
    {--database= : Database name}
    {--username= : Database user}
    {--password= : Database password (or set IMPORT_DB_PASSWORD)}
    {--prefix= : Table prefix, if the tables have one}
    {--key= : The other system\'s encryption key, when it asks for one}
    {--check : Only test the connection and count the records}
    {--dry-run : Show what would be imported and any problems, without changing anything}')]
#[Description('Import clients, products, services, domains, invoices, payments and tickets from another billing system')]
class ImportFrom extends Command
{
    public function handle(): int
    {
        $saved = ImportSources::connection();
        $source = (string) ($this->argument('source') ?: ($saved['source'] ?? 'whmcs'));

        if (! isset(ImportSources::SOURCES[$source])) {
            $this->components->error('Unknown system "'.$source.'". Choose one of: '.implode(', ', array_keys(ImportSources::SOURCES)).'.');

            return self::INVALID;
        }

        $class = ImportSources::get($source);
        $connection = filled($this->option('database'))
            ? [
                'source' => $source,
                'host' => $this->option('host') ?: 'localhost',
                'port' => $this->option('port'),
                'database' => $this->option('database'),
                'username' => $this->option('username'),
                'password' => $this->option('password') ?? (getenv('IMPORT_DB_PASSWORD') ?: (getenv('WHMCS_DB_PASSWORD') ?: ($this->input->isInteractive() ? (string) $this->secret('Database password') : ''))),
                'prefix' => (string) $this->option('prefix'),
                'key' => (string) $this->option('key'),
            ]
            : ['source' => $source, 'key' => $this->option('key') ?: ($saved['key'] ?? null)] + $saved;

        if (blank($connection['database'] ?? null) || ($saved['source'] ?? $source) !== $source && blank($this->option('database'))) {
            $this->components->error('Give the database details with --database, --username and --password, or save them in Settings → Import.');

            return self::INVALID;
        }

        try {
            $importer = $class::connect($connection);
            $check = $importer->check();
        } catch (Throwable $exception) {
            $this->components->error('Could not read the '.$class::name().' database: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($class::keyChecksPasswords() && filled($connection['key'] ?? null)) {
            app(Settings::class)->set('import.password_keys', [$source => (string) $connection['key']] + (array) setting('import.password_keys', []));
        }

        $this->components->info('Connected to '.$class::name().' '.($check['version'] ?: '(unknown version)').'.');

        if ($this->option('check')) {
            foreach ($class::steps() as $step => $label) {
                $this->components->twoColumnDetail($label, number_format($check['counts'][$step]));
            }

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            return $this->showPreflight($importer->preflight()->toArray());
        }

        $failed = 0;

        foreach ($class::steps() as $step => $label) {
            $totals = ['created' => 0, 'updated' => 0, 'skipped' => 0];
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 500);
                $afterId = $result['last_id'];

                foreach ($totals as $key => $count) {
                    $totals[$key] = $count + $result[$key];
                }

                foreach ($result['errors'] as $error) {
                    $failed++;
                    $this->components->warn($label.' #'.$error['id'].': '.$error['error']);
                }
            } while (! $result['done']);

            $this->components->twoColumnDetail($label, "added {$totals['created']}, updated {$totals['updated']}, skipped {$totals['skipped']}");
        }

        Activity::log('import.finished', $class::name().' import finished (command line).');
        $this->components->info($failed > 0 ? "Done. {$failed} rows could not be imported (listed above)." : 'Done. Check the servers in Servers and switch them on.');

        return self::SUCCESS;
    }

    /**
     * @param  array{system: string, version: string, steps: array<string, array{label: string, total: int, new: int, existing: int, skipped: int}>, problems: list<array{level: string, text: string, params: array<string, string|int>, examples: list<string>}>}  $preview
     */
    private function showPreflight(array $preview): int
    {
        $this->newLine();
        $this->line('  <options=bold>Dry run: nothing was changed</>');

        foreach ($preview['steps'] as $step) {
            $this->components->twoColumnDetail($step['label'], "{$step['total']} found: {$step['new']} new, {$step['existing']} imported before");
        }

        $this->newLine();

        foreach ($preview['problems'] as $problem) {
            $text = __($problem['text'], $problem['params'], 'en').($problem['examples'] !== [] ? ' (for example: '.implode(', ', $problem['examples']).')' : '');

            match ($problem['level']) {
                Preflight::ERROR => $this->components->error($text),
                Preflight::WARNING => $this->components->warn($text),
                default => $this->components->info($text),
            };
        }

        return collect($preview['problems'])->contains('level', Preflight::ERROR) ? self::FAILURE : self::SUCCESS;
    }
}
