<?php

namespace Tests\Fixtures\Addons;

use App\Extensions\Addons\Addon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;

/**
 * Uses every add-on hook, for the tests.
 */
class ProbeAddon extends Addon
{
    public static int $runs = 0;

    public function settingsFields(): array
    {
        return ['label' => ['label' => 'Label', 'type' => 'text']];
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => self::$runs++)->everyMinute()->name('probe-run');
    }

    public function adminRoutes(): void
    {
        Route::post('connect', fn (): RedirectResponse => $this->connect())->name('connect');
    }

    public function settingsHtml(): string
    {
        return '<p class="probe-panel">'.e($this->setting('token') ? __('Probe is connected.') : 'Probe is not connected.').'</p>'
            .'<form method="POST" action="'.route($this->routeName('connect')).'"></form>';
    }

    private function connect(): RedirectResponse
    {
        $this->remember(['token' => 'secret-token']);

        return redirect()->route('admin.marketplace.settings', $this->slug());
    }
}
