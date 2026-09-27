<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Security\WebAuthn\Passkeys;
use App\Security\WebAuthn\WebAuthnException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Staff sign-in with a passkey. The passkey already checks the person (fingerprint, face or PIN),
 * so there is no extra two-factor step.
 */
class PasskeyLoginController extends Controller
{
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        return response()->json($passkeys->requestOptions($request, 'admin'));
    }

    public function store(Request $request, Passkeys $passkeys): RedirectResponse
    {
        $request->validate(['credential' => ['required', 'string', 'max:20000']]);

        try {
            $admin = $passkeys->verify($request, 'admin', (string) $request->input('credential'))->owner;
        } catch (WebAuthnException $exception) {
            Log::info('Staff passkey sign-in failed: '.$exception->getMessage(), ['ip' => $request->ip()]);

            return back()->withErrors(['email' => __('That passkey did not work. Try again or sign in with your password.')]);
        }

        if (! $admin instanceof Admin || ! $admin->is_active) {
            return back()->withErrors(['email' => __('Your staff account is turned off.')]);
        }

        return LoginController::completeLogin($request, $admin, $request->boolean('remember'));
    }
}
