<?php

namespace App\Http\Middleware;

use App\Models\Admin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks staff whose role does not include the permission, for example "billing.manage".
 * Also signs out staff accounts that were deactivated while signed in.
 */
class EnsureAdminPermission
{
    public function handle(Request $request, Closure $next, ?string $permission = null): Response
    {
        $admin = $request->user('admin');

        if (! $admin instanceof Admin || ! $admin->is_active) {
            Auth::guard('admin')->logout();

            return redirect()->route('admin.login')->withErrors(['email' => __('Your staff account is turned off.')]);
        }

        if ($permission !== null && ! $admin->hasPermission($permission)) {
            abort(403, __('Your role does not allow this. Ask the owner to give you the ":permission" permission.', ['permission' => $permission]));
        }

        return $next($request);
    }
}
