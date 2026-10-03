<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Security\EmailCode;
use App\Security\OwnerCheck;
use App\Security\Totp;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Account → Two-factor sign-in: an authenticator app or a code by email, as staff allow in Settings → Security.
 */
class TwoFactorController extends Controller
{
    public function startApp(Request $request): RedirectResponse
    {
        $client = $request->user('web');

        if (! $this->allows(Client::TWO_FACTOR_APP) || $client->hasTwoFactorEnabled()) {
            return back();
        }

        $client->forceFill([
            'two_factor_method' => Client::TWO_FACTOR_APP,
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->withFragment('two-factor');
    }

    public function confirmApp(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $client = $request->user('web');

        if ($client->two_factor_method !== Client::TWO_FACTOR_APP || $client->two_factor_secret === null || ! Totp::verify($client->two_factor_secret, $request->input('code'))) {
            return back()->withErrors(['code' => __('That code is not right. Scan the QR code again and enter the newest code.')])->withFragment('two-factor');
        }

        $codes = Totp::generateRecoveryCodes();
        $client->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        Activity::log('client.two_factor_on', "{$client->name} turned on two-factor sign-in (app)", $client, $client, $client);

        return back()->with('status', __('Two-factor sign-in is on.'))->with('recovery_codes', $codes)->withFragment('two-factor');
    }

    public function startEmail(Request $request, EmailCode $codes): RedirectResponse
    {
        $client = $request->user('web');

        if (! $this->allows(Client::TWO_FACTOR_EMAIL) || $client->hasTwoFactorEnabled()) {
            return back();
        }

        $codes->send($client, 'setup');

        return back()->with('status', __('We sent a code to :email. Enter it below.', ['email' => $client->email]))->withFragment('two-factor');
    }

    public function confirmEmail(Request $request, EmailCode $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $client = $request->user('web');

        if (! $this->allows(Client::TWO_FACTOR_EMAIL) || ! $codes->check($client, 'setup', $request->input('code'))) {
            return back()->withErrors(['code' => __('That code is not right, or it is too old. Ask for a new code.')])->withFragment('two-factor');
        }

        $client->forceFill([
            'two_factor_method' => Client::TWO_FACTOR_EMAIL,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => now(),
        ])->save();
        Activity::log('client.two_factor_on', "{$client->name} turned on two-factor sign-in (email)", $client, $client, $client);

        return back()->with('status', __('Two-factor sign-in is on. We will email you a code each time you sign in.'))->withFragment('two-factor');
    }

    /**
     * Turn off two-factor sign-in, or cancel a setup that was not finished.
     */
    public function destroy(Request $request, OwnerCheck $owner): RedirectResponse
    {
        $client = $request->user('web');

        if ($client->hasTwoFactorEnabled()) {
            if (setting('security.client_two_factor') === 'required') {
                return back()->with('error', __('Two-factor sign-in is required for all accounts.'));
            }

            // The password, or for clients without one a code sent to their email: a stolen session
            // alone cannot turn it off.
            $owner->confirm($request, $client, 'two_factor_');

            Activity::log('client.two_factor_off', "{$client->name} turned off two-factor sign-in", $client, $client, $client);
        }

        $client->forceFill([
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return back()->with('status', __('Two-factor sign-in is off.'))->withFragment('two-factor');
    }

    private function allows(string $method): bool
    {
        return setting('security.client_two_factor') !== 'off'
            && in_array($method, (array) setting('security.client_two_factor_methods'), true);
    }
}
