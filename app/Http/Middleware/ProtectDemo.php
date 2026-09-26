<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * On the public demo, turns away changes listed in Demo::LOCKED_ROUTES and changes to the
 * shared demo client, and keeps search engines out.
 */
class ProtectDemo
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Demo::isEnabled()) {
            return $next($request);
        }

        if ($this->isLocked($request)) {
            $message = __('This is turned off in the demo, so it keeps working for every visitor.');

            return $request->expectsJson()
                ? response()->json(['message' => $message], 403)
                : back()->with('error', $message);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function isLocked(Request $request): bool
    {
        if ($request->isMethodSafe()) {
            return false;
        }

        if ($request->routeIs(...Demo::LOCKED_ROUTES)) {
            return true;
        }

        if (! $request->routeIs('admin.clients.update')) {
            return false;
        }

        $client = $request->route('client');
        $client = $client instanceof Client ? $client : Client::query()->find($client);

        return $client?->email === Demo::CLIENT_EMAIL;
    }
}
