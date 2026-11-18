<?php

namespace App\Http\Middleware;

use App\Support\Installation;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends visitors and search engines from an address with a language the site does not offer,
 * such as /?lang=da or a language staff switched off, to the same page without it. Runs before
 * the session starts, so the redirect sets no cookies.
 */
class CleanPublicAddress
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        if (! $request->query->has('lang') || $this->isPrivateArea($request)) {
            return $next($request);
        }

        $lang = $request->query('lang');
        // A broken database or a site that is not installed yet: leave the address alone.
        $enabled = rescue(fn (): ?array => Installation::isInstalled() ? Locales::enabled() : null, null, report: false);

        if ($enabled === null || (is_string($lang) && isset($enabled[$lang]))) {
            return $next($request);
        }

        $query = $request->query();
        unset($query['lang']);
        $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        // Always this site's own address: the path is added to it, never read as an address.
        $path = trim($request->path(), '/');
        $target = rtrim(url('/'), '/').($path === '' ? '' : '/'.$path);

        return redirect($target.($query === '' ? '' : '?'.$query), 301);
    }

    private function isPrivateArea(Request $request): bool
    {
        $adminPath = trim((string) config('nuvabill.admin_path'), '/');

        return $request->is($adminPath, $adminPath.'/*', 'install', 'install/*');
    }
}
