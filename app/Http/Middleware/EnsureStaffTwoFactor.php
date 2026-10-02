<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When two-factor login is required for staff (Settings → Security), staff without it can only
 * open their profile to set it up, change their password or passkeys, remove old API keys and phone
 * alerts, and sign out. Making new API keys or phone alerts waits until two-factor login is on.
 */
class EnsureStaffTwoFactor
{
    /**
     * Pages staff can still use while they set up two-factor login.
     */
    public const SETUP_ROUTES = [
        'admin.profile.edit', 'admin.profile.password', 'admin.profile.two-factor.*', 'admin.profile.passkeys.*',
        'admin.profile.api-keys.destroy', 'admin.profile.push.destroy', 'admin.profile.push.forget',
        'admin.language', 'admin.logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin !== null
            && self::mustSetUp($admin)
            && ! $request->routeIs(...self::SETUP_ROUTES)
            && ! Demo::isEnabled()) {
            return redirect()->route('admin.profile.edit')
                ->with('error', __('Your company requires two-factor login for staff. Set it up below to continue.'));
        }

        return $next($request);
    }

    /**
     * Whether this staff member must turn on two-factor login before using the admin area or the API.
     */
    public static function mustSetUp(Admin $admin): bool
    {
        return setting('security.staff_two_factor') === 'required' && ! $admin->hasTwoFactorEnabled();
    }
}
