<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance as Middleware;

/**
 * Keeps the updater and staff sign-in reachable while the site is in maintenance mode during an update.
 */
class PreventRequestsDuringMaintenance extends Middleware
{
    /**
     * @return array<int, string>
     */
    public function getExcludedPaths()
    {
        $admin = trim((string) config('nuvabill.admin_path'), '/');

        return array_merge(parent::getExcludedPaths(), [
            $admin.'/updates',
            $admin.'/updates/*',
            $admin.'/login',
            $admin.'/two-factor',
        ]);
    }
}
