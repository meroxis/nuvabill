<?php

use App\Chat\WhatsAppSetup;
use App\Extensions\ExtensionManager;
use App\Support\Demo;
use App\Support\Installation;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| One cron entry runs everything: * * * * * php /path/to/artisan schedule:run
| The queue worker runs from cron too, so shared hosting needs no daemon.
|
*/

if (Installation::isInstalled()) {
    // The run takes its own lock too; the scheduler's lock expires after two hours so a run that
    // crashed never holds up the next night.
    Schedule::command('nuvabill:cron')->dailyAt('00:15')->withoutOverlapping(120);

    // The lock outlasts one run (50 seconds plus one long job), but not a day if a crash leaves it behind.
    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')->everyMinute()->withoutOverlapping(10);

    // Automations: runs whose wait is over, and the timed triggers once a day.
    Schedule::command('nuvabill:automations')->everyFiveMinutes()->withoutOverlapping(30);

    if (Demo::isEnabled()) {
        // Every hour, and within a minute on a new demo site with an empty database.
        Schedule::command('nuvabill:demo-reset')->everyMinute()->withoutOverlapping(30)
            ->when(fn (): bool => now()->minute === 0 || ! Demo::hasData());
    } else {
        // An update keeps the site in maintenance mode until it is finished, so this one runs then too.
        // The updater takes its own lock, so it never runs alongside a finish from the browser.
        Schedule::command('nuvabill:update --finish-pending')->everyMinute()->evenInMaintenanceMode()->withoutOverlapping(30);

        Schedule::command('nuvabill:update --auto')->dailyAt('03:00')->withoutOverlapping();

        Schedule::command('nuvabill:marketplace-licenses')->dailyAt('04:20')->withoutOverlapping();

        // Network status: can each server be reached? Staff get an email when one goes down.
        Schedule::command('nuvabill:server-status')->everyFiveMinutes()->withoutOverlapping(10)
            ->when(fn (): bool => (bool) setting('status.checks'));

        // Site health: the nightly check, then database clean-up and the weekly optimize when switched on.
        Schedule::command('nuvabill:security-check --quiet-if-healthy')->dailyAt('04:30')->withoutOverlapping(60)
            ->when(fn (): bool => (bool) setting('health.nightly'));
        Schedule::command('nuvabill:security-check --quiet-if-healthy')->everyMinute()->withoutOverlapping(30)
            ->when(fn (): bool => setting('health.nightly') && setting('health.check_requested'));
        Schedule::command('nuvabill:database --clean --scheduled')->dailyAt('04:40')->withoutOverlapping(60);
        Schedule::command('nuvabill:database --optimize --scheduled')->weeklyOn(0, '04:50')->withoutOverlapping(60);

        // WhatsApp: Meta only tells its own app about template decisions, so ask until all are decided.
        Schedule::call(fn () => rescue(fn () => app(WhatsAppSetup::class)->refreshTemplates(), report: false))
            ->hourly()->name('nuvabill:whatsapp-templates')->withoutOverlapping(30)
            ->when(fn (): bool => app(WhatsAppSetup::class)->hasUndecidedTemplates());

        // Scheduled work of switched-on add-ons, for example off-site backups.
        app(ExtensionManager::class)->scheduleAddons(Schedule::getFacadeRoot());
    }
}
