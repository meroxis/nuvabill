<?php

namespace App\Listeners;

use App\Models\Admin;
use Illuminate\Auth\Events\Login;

/**
 * Notes when a staff member was last signed in, also when "Keep me signed in on this device"
 * signs them in again after their session ended. Site health uses it to find unused accounts,
 * so someone who works every day is never listed as idle.
 */
class RecordStaffSignIn
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'admin' || ! $event->user instanceof Admin) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now(), 'last_login_ip' => request()->ip()])->saveQuietly();
    }
}
