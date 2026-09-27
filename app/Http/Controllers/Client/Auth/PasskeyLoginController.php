<?php

namespace App\Http\Controllers\Client\Auth;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Security\WebAuthn\Passkeys;
use App\Security\WebAuthn\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Client sign-in with a passkey. The passkey already checks the person (fingerprint, face or PIN),
 * so there is no extra two-factor step.
 */
class PasskeyLoginController extends Controller
{
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        return response()->json($passkeys->requestOptions($request, 'client'));
    }

    public function store(Request $request, Passkeys $passkeys): RedirectResponse
    {
        $request->validate(['credential' => ['required', 'string', 'max:20000']]);

        try {
            $client = $passkeys->verify($request, 'client', (string) $request->input('credential'))->owner;
        } catch (WebAuthnException $exception) {
            Log::info('Client passkey sign-in failed: '.$exception->getMessage(), ['ip' => $request->ip()]);

            return back()->withErrors(['email' => __('That passkey did not work. Try again or sign in with your password.')]);
        }

        if (! $client instanceof Client || $client->status === ClientStatus::Closed) {
            return back()->withErrors(['email' => __('This account is closed. Contact support if you need help.')]);
        }

        return LoginController::completeLogin($request, $client, $request->boolean('remember'), 'with a passkey');
    }
}
