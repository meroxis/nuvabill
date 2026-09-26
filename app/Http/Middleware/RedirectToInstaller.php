<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends every visitor to the web installer until it has finished, then closes the installer.
 */
class RedirectToInstaller
{
    public function handle(Request $request, Closure $next): Response
    {
        $isInstallerRoute = $request->routeIs('install.*');

        if (! Installation::isInstalled() && ! $isInstallerRoute) {
            return redirect()->route('install.welcome');
        }

        if (Installation::isInstalled() && $isInstallerRoute) {
            abort(404);
        }

        return $next($request);
    }
}
