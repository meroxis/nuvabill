<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Passkey;
use App\Security\OwnerCheck;
use App\Security\WebAuthn\Passkeys;
use App\Security\WebAuthn\WebAuthnException;
use App\Support\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Account → Passkeys. A passkey is a lasting way to sign in, so adding one needs the current
 * password, or for clients without one a code sent to their email (OwnerCheck): a stolen session
 * alone cannot add a way back in.
 */
class PasskeyController extends Controller
{
    /**
     * @throws ValidationException when the password or code is wrong (a 422 answer the page shows)
     */
    public function options(Request $request, Passkeys $passkeys, OwnerCheck $owner): JsonResponse
    {
        $client = $request->user('web');

        $owner->confirm($request, $client, 'passkey_');

        return response()->json($passkeys->creationOptions($request, $client));
    }

    public function store(Request $request, Passkeys $passkeys): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'credential' => ['required', 'string', 'max:20000'],
        ]);

        $client = $request->user('web');

        try {
            $passkey = $passkeys->register($request, $client, $data['credential'], $data['name'] ?: __('Passkey'));
        } catch (WebAuthnException $exception) {
            Log::info('Passkey not added: '.$exception->getMessage(), ['client_id' => $client->id]);

            return redirect()->to(route('client.account.edit').'#passkeys')->with('error', __('The passkey could not be added. Try again.'));
        }

        Activity::log('client.passkey_added', "{$client->name} added the passkey \"{$passkey->name}\"", $client, $client, $client);

        return redirect()->to(route('client.account.edit').'#passkeys')->with('status', __('Passkey added. You can sign in with it now.'));
    }

    public function destroy(Request $request, Passkey $passkey): RedirectResponse
    {
        $client = $request->user('web');
        abort_unless($passkey->owner_type === $client->getMorphClass() && $passkey->owner_id === $client->id, 404);

        $passkey->delete();
        Activity::log('client.passkey_removed', "{$client->name} removed the passkey \"{$passkey->name}\"", $client, $client, $client);

        return redirect()->to(route('client.account.edit').'#passkeys')->with('status', __('Passkey removed.'));
    }
}
