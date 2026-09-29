<?php

namespace App\Jobs;

use App\Models\Admin;
use App\Push\StaffAlerts;
use App\Push\WebPush;
use App\Support\Locales;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends one push alert to every phone of the given staff members, in each one's language. A phone
 * that cannot be reached is skipped; one the push service no longer knows is forgotten.
 */
class SendStaffPush implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param  list<int>  $adminIds
     * @param  array<string, string>  $data
     */
    public function __construct(public array $adminIds, public string $kind, public array $data) {}

    public function handle(WebPush $push): void
    {
        foreach (Admin::query()->whereIn('id', $this->adminIds)->with('pushSubscriptions')->get() as $admin) {
            $locale = Locales::isSupported($admin->language) ? (string) $admin->language : Locales::default();
            $message = StaffAlerts::message($this->kind, $this->data, $locale);

            foreach ($admin->pushSubscriptions as $subscription) {
                rescue(fn () => $push->send($subscription, $message), report: false);
            }
        }
    }
}
