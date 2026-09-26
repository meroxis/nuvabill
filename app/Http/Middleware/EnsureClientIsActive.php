<?php

namespace App\Http\Middleware;

use App\Enums\ClientStatus;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out clients whose account was closed.
 */
class EnsureClientIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user('web');

        if ($client instanceof Client && $client->status === ClientStatus::Closed) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('client.login')->withErrors(['email' => __('This account is closed. Contact support if you need help.')]);
        }

        return $next($request);
    }
}
