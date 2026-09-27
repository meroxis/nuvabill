<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When two-factor login is required for staff (Settings → Security), staff without it can only
 * open their profile (to set it up) and sign out.
 */
class EnsureStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->user('admin');

        if ($admin !== null
            && setting('security.staff_two_factor') === 'required'
            && ! $admin->hasTwoFactorEnabled()
            && ! $request->routeIs('admin.profile.*', 'admin.language', 'admin.logout')
            && ! Demo::isEnabled()) {
            return redirect()->route('admin.profile.edit')
                ->with('error', __('Your company requires two-factor login for staff. Set it up below to continue.'));
        }

        return $next($request);
    }
}
