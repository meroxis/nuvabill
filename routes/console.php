<?php

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
    Schedule::command('nuvabill:update --finish-pending')->everyMinute()->withoutOverlapping();

    Schedule::command('nuvabill:cron')->dailyAt('00:15')->withoutOverlapping();

    Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=1')->everyMinute()->withoutOverlapping();

    Schedule::command('nuvabill:update --auto')->dailyAt('03:00')->withoutOverlapping();
}
