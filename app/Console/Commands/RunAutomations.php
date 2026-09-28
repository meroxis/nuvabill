<?php

namespace App\Console\Commands;

use App\Automations\Runner;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

#[Signature('nuvabill:automations {--scan : Check the timed triggers now, even if they were checked today}')]
#[Description('Go on with automation runs whose wait is over, and check the timed triggers once a day')]
class RunAutomations extends Command
{
    public function handle(Runner $runner, Settings $settings): int
    {
        $lock = Cache::lock('nuvabill:automations', 600);

        if (! $lock->get()) {
            $this->components->warn('Another automations run is busy right now.');

            return self::SUCCESS;
        }

        try {
            $resumed = $runner->resumeDue();
            $started = 0;
            $today = CarbonImmutable::today();

            // Timed triggers are checked once a day, from the hour set in automations.scan_hour, so
            // reminders arrive in the morning rather than at midnight.
            if ($this->option('scan') || (setting('automations.last_scan') !== $today->toDateString() && now()->hour >= (int) setting('automations.scan_hour'))) {
                $settings->set('automations.last_scan', $today->toDateString());
                $started = $runner->scan($today);
            }

            $this->components->info("Automation runs continued: {$resumed}, started by timed triggers: {$started}");
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
