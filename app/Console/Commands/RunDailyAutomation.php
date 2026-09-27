<?php

namespace App\Console\Commands;

use App\Automation\DailyAutomation;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('nuvabill:cron')]
#[Description('Run the daily billing automation: renewal invoices, reminders, suspensions and terminations')]
class RunDailyAutomation extends Command
{
    public function handle(DailyAutomation $automation, Settings $settings): int
    {
        if (! setting('automation.enabled')) {
            $this->components->warn('Automation is turned off in Settings → Automation.');

            return self::SUCCESS;
        }

        $summary = $automation->run();
        $settings->set('automation.last_run_at', now()->toIso8601String());

        $line = "Invoices created: {$summary['invoices']}, reminders: {$summary['reminders']}, suspended: {$summary['suspended']}, terminated: {$summary['terminated']}, failed: {$summary['failed']}, domains expired: {$summary['domains_expired']}, commissions released: {$summary['commissions']}";

        Activity::log('automation.run', 'Daily automation: '.$line);
        $this->components->info($line);

        return self::SUCCESS;
    }
}
