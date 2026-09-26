<?php

namespace App\Http\Middleware;

use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * When staff require two-factor sign-in for clients, a client without it can only use the Account page
 * (to set it up) until it is on.
 */
class EnsureClientTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user('web');

        if ($client !== null
            && setting('security.client_two_factor') === 'required'
            && ! $client->hasTwoFactorEnabled()
            && ! $request->routeIs('client.account.*')
            && ! Demo::isEnabled()) {
            return redirect()->to(route('client.account.edit').'#two-factor')
                ->with('error', __('Please turn on two-factor sign-in to keep using your account. It takes one minute.'));
        }

        return $next($request);
    }
}
