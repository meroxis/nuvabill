<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Demo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs REST API requests in as the staff member who owns the Bearer token. Read-only tokens can
 * only use GET. Everything else, like which clients or invoices they may see, follows the role
 * of that staff member through the usual admin.can checks.
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Demo::isEnabled()) {
            return response()->json(['message' => __('The API is turned off on the demo.')], 403);
        }

        $token = ApiToken::findByPlainText((string) $request->bearerToken());
        $admin = $token?->admin;

        if ($token === null || $admin === null || ! $admin->is_active) {
            return response()->json(['message' => __('Send a valid API key in the Authorization header: Bearer nb_…')], 401);
        }

        if (! $token->can_write && ! $request->isMethodSafe()) {
            return response()->json(['message' => __('This API key can only read. Make a key that can write to change data.')], 403);
        }

        Auth::guard('admin')->setUser($admin);
        $request->attributes->set('api_token', $token);

        if ($token->last_used_at === null || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->save();
        }

        return $next($request);
    }
}
