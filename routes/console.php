<?php

use App\Auth\LegacyPassword;
use App\Chat\WhatsAppSetup;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\ModuleHooks;
use App\Jobs\TranslateTicketReply;
use App\Models\Machine;
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

    // The default queue first: AI translations wait until services and domains are set up. The lock outlasts
    // one run (50 seconds of other jobs plus one VPS setup of up to 15 minutes), but not a day if a crash
    // leaves it behind.
    $queues = (config('queue.connections.'.config('queue.default').'.queue') ?: 'default').','.TranslateTicketReply::QUEUE;
    Schedule::command("queue:work --queue={$queues} --stop-when-empty --max-time=50 --tries=1")->everyMinute()->withoutOverlapping(20);

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

        // Connect to marketplace: keep the connection alive, renew the key every 90 days, and notice
        // when this server goes out from a new address. Only for connected sites, each at its own minute
        // between 05:00 and 05:59, so not every site calls the store at once.
        Schedule::command('nuvabill:marketplace-heartbeat')->dailyAt(sprintf('05:%02d', crc32((string) config('app.url')) % 60))->withoutOverlapping()
            ->when(fn (): bool => filled(setting('marketplace.site_key')));

        // The store only: connected sites not seen for 60 days, and requests never approved, end.
        Schedule::command('nuvabill:marketplace-sites-prune')->dailyAt('06:10')->withoutOverlapping()
            ->when(fn (): bool => (bool) config('nuvabill.marketplace.store'));

        // Network status: can each server be reached? Staff get an email when one goes down.
        Schedule::command('nuvabill:server-status')->everyFiveMinutes()->withoutOverlapping(10)
            ->when(fn (): bool => (bool) setting('status.checks'));

        // Dedicated servers: free machines whose service ended, and switch off suspended or released
        // ones. Nothing runs until a machine exists (or while an update has not made the table yet).
        Schedule::command('nuvabill:hardware')->everyFiveMinutes()->withoutOverlapping(10)
            ->when(fn (): bool => (bool) rescue(fn (): bool => Machine::query()->exists(), false, false));

        // Site health: the nightly check, then database clean-up and the weekly optimize when switched on.
        Schedule::command('nuvabill:security-check --quiet-if-healthy')->dailyAt('04:30')->withoutOverlapping(60)
            ->when(fn (): bool => (bool) setting('health.nightly'));
        Schedule::command('nuvabill:security-check --quiet-if-healthy')->everyMinute()->withoutOverlapping(30)
            ->when(fn (): bool => setting('health.nightly') && setting('health.check_requested'));
        Schedule::command('nuvabill:database --clean --scheduled')->dailyAt('04:40')->withoutOverlapping(60);
        Schedule::command('nuvabill:database --optimize --scheduled')->weeklyOn(0, '04:50')->withoutOverlapping(60);

        // Imported clients: old MD5 password hashes are wrapped in Nuvabill's own slow hash, a few at a
        // time, until none is left. After that the clients table is only looked at once an hour.
        Schedule::call(fn () => rescue(fn () => LegacyPassword::wrapStored()))
            ->everyMinute()->name('nuvabill:imported-passwords')->withoutOverlapping(10)
            ->when(fn (): bool => LegacyPassword::mayHaveFastHashes());

        // WhatsApp: Meta only tells its own app about template decisions, so ask until all are decided.
        Schedule::call(fn () => rescue(fn () => app(WhatsAppSetup::class)->refreshTemplates(), report: false))
            ->hourly()->name('nuvabill:whatsapp-templates')->withoutOverlapping(30)
            ->when(fn (): bool => app(WhatsAppSetup::class)->hasUndecidedTemplates());

        // Scheduled work of switched-on add-ons, for example off-site backups.
        app(ExtensionManager::class)->scheduleAddons(Schedule::getFacadeRoot());

        // Scheduled work of server modules, for example traffic checks. A broken module is left out.
        rescue(fn () => app(ModuleHooks::class)->schedule(Schedule::getFacadeRoot()));
    }
}
