<?php

namespace App\Console\Commands;

use App\Support\SiteBackup;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Number;
use Throwable;

#[Signature('nuvabill:backup
    {--database : Back up only the database (the default is the whole site)}
    {--password= : Encrypt the backup with this password (AES-256)}')]
#[Description('Make a backup of the whole site (all files and the database), or of the database only')]
class BackupSite extends Command
{
    public function handle(SiteBackup $backup): int
    {
        $type = $this->option('database') ? SiteBackup::TYPE_DATABASE : SiteBackup::TYPE_SITE;

        try {
            $path = $backup->create($type, $this->option('password'));
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Backup made: '.$path.' ('.Number::fileSize((int) filesize($path)).')');
        $this->line('  Keep a copy somewhere else. Read RESTORE.txt inside it to put the site back.');

        return self::SUCCESS;
    }
}
