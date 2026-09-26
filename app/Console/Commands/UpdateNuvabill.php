<?php

namespace App\Console\Commands;

use App\Updates\UpdateManager;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('nuvabill:update
    {--check : Only check for a new version}
    {--auto : Install automatically if the update settings allow it (used by the scheduler)}
    {--finish-pending : Finish an update that already copied its files}')]
#[Description('Check for and install Nuvabill updates')]
class UpdateNuvabill extends Command
{
    public function handle(UpdateManager $updates): int
    {
        try {
            if ($this->option('finish-pending')) {
                return $this->finishPending($updates);
            }

            $release = $updates->check();

            if ($release === null) {
                $this->components->info("Nuvabill {$updates->currentVersion()} is up to date.");

                return self::SUCCESS;
            }

            $this->components->info("Version {$release->version} is available (you have {$updates->currentVersion()}).");

            if ($this->option('check') || ($this->option('auto') && ! $updates->shouldAutoInstall($release))) {
                return self::SUCCESS;
            }

            $this->components->task("Downloading, verifying and installing {$release->version}", fn () => $updates->install($release));

            return $this->finishInNewProcess();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function finishPending(UpdateManager $updates): int
    {
        if (! $updates->hasPendingFinish()) {
            return self::SUCCESS;
        }

        $result = $updates->finish();
        $this->components->info("Updated from {$result['from']} to {$result['to']}.");

        return self::SUCCESS;
    }

    /**
     * The new files are on disk, but this process still runs the old code, so migrations run in a new process.
     * If processes cannot be started here, the scheduler finishes the update within a minute.
     */
    private function finishInNewProcess(): int
    {
        $php = (new PhpExecutableFinder)->find() ?: PHP_BINARY;

        try {
            $process = new Process([$php, base_path('artisan'), 'nuvabill:update', '--finish-pending'], base_path(), null, null, 600);
            $process->run(fn (string $type, string $output) => $this->output->write($output));

            return $process->isSuccessful() ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            $this->components->warn('Files are updated. The scheduler will finish the update within a minute, or run: php artisan nuvabill:update --finish-pending');

            return self::SUCCESS;
        }
    }
}
