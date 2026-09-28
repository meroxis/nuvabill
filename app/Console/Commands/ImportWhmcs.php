<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:import-whmcs
    {--host= : WHMCS database host (empty uses the details saved in Settings → Import)}
    {--port=3306 : WHMCS database port}
    {--database= : WHMCS database name}
    {--username= : WHMCS database user}
    {--password= : WHMCS database password (or set WHMCS_DB_PASSWORD)}
    {--key= : $cc_encryption_hash from WHMCS\'s configuration.php, to import server and service passwords}
    {--check : Only test the connection and count the records}
    {--dry-run : Show what would be imported and any problems, without changing anything}')]
#[Description('Import from a WHMCS database (the same as "nuvabill:import whmcs")')]
class ImportWhmcs extends Command
{
    public function handle(): int
    {
        return $this->call('nuvabill:import', array_filter([
            'source' => 'whmcs',
            '--host' => $this->option('host'),
            '--port' => $this->option('port'),
            '--database' => $this->option('database'),
            '--username' => $this->option('username'),
            '--password' => $this->option('password'),
            '--key' => $this->option('key'),
            '--check' => $this->option('check'),
            '--dry-run' => $this->option('dry-run'),
        ], fn (mixed $value): bool => $value !== null && $value !== false));
    }
}
