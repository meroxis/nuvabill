<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Security\WebAuthn\Passkeys;
use App\Security\WebAuthn\WebAuthnException;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Your profile → Passkeys. Adding one asks for the password first, so a stolen session
 * cannot quietly add a way back in.
 */
class PasskeyController extends Controller
{
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        $admin = $request->user('admin');

        if (! Hash::check((string) $request->input('current_password'), $admin->password)) {
            return response()->json(['message' => __('Your password is not right.')], 422);
        }

        return response()->json($passkeys->creationOptions($request, $admin));
    }

    public function store(Request $request, Passkeys $passkeys): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'credential' => ['required', 'string', 'max:20000'],
        ]);

        $admin = $request->user('admin');

        try {
            $passkey = $passkeys->register($request, $admin, $data['credential'], $data['name'] ?: __('Passkey'));
        } catch (WebAuthnException $exception) {
            Log::info('Passkey not added: '.$exception->getMessage(), ['admin_id' => $admin->id]);

            return back()->with('error', __('The passkey could not be added. Try again.'));
        }

        Activity::log('admin.passkey_added', "{$admin->name} added the passkey \"{$passkey->name}\"", actor: $admin);

        return back()->with('status', __('Passkey added. You can sign in with it now.'));
    }

    public function destroy(Request $request, Passkey $passkey): RedirectResponse
    {
        $admin = $request->user('admin');
        abort_unless($passkey->owner_type === $admin->getMorphClass() && $passkey->owner_id === $admin->id, 404);

        $passkey->delete();
        Activity::log('admin.passkey_removed', "{$admin->name} removed the passkey \"{$passkey->name}\"", actor: $admin);

        return back()->with('status', __('Passkey removed.'));
    }
}
