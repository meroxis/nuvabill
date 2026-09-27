<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the language of the page. Staff and clients each keep their own choice, so a staff
 * member can work in English while previewing the client area in Kurdish.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $adminPath = trim((string) config('nuvabill.admin_path'), '/');
        $adminArea = $request->is($adminPath, $adminPath.'/*');

        app()->setLocale(rescue(fn (): string => Locales::forRequest($request, $adminArea), 'en', report: false));

        return $next($request);
    }
}
