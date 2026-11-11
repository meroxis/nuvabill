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
use Illuminate\View\View;

/**
 * Account → Two-factor sign-in: an authenticator app or a code by email, as staff allow in Settings → Security.
 *
 * Whoever turns it on decides who can sign in afterwards. So turning it on needs the same proof as
 * turning it off (OwnerCheck: the password, or for clients without one a code sent to their email):
 * a stolen session alone cannot add its own app and keep the owner out.
 */
class TwoFactorController extends Controller
{
    /** Session key: this session gave that proof and started a setup, which may take this long. */
    private const SETUP = 'client.two_factor_setup';

    private const SETUP_MINUTES = 15;

    /**
     * A page that asks for the proof before a setup starts. The default theme's Account page has
     * the field in its own forms; a theme whose forms do not send it yet is brought here, so its
     * clients can still turn two-factor sign-in on.
     */
    public function proof(Request $request, string $method): View|RedirectResponse
    {
        $client = $request->user('web');

        // Nothing to ask: the method is switched off, two-factor sign-in is on already, or (codes by
        // email, no password) the code of the setup itself is the proof.
        if (! $this->allows($method === 'email' ? Client::TWO_FACTOR_EMAIL : Client::TWO_FACTOR_APP)
            || $client->hasTwoFactorEnabled()
            || ($method === 'email' && ! $client->has_password)) {
            return $this->toAccount();
        }

        return view('theme::client.two-factor-start', ['client' => $client, 'method' => $method]);
    }

    public function startApp(Request $request, OwnerCheck $owner): RedirectResponse
    {
        $client = $request->user('web');

        if (! $this->allows(Client::TWO_FACTOR_APP) || $client->hasTwoFactorEnabled()) {
            return back();
        }

        if ($this->sentNoProof($request, $client)) {
            return redirect()->route('client.account.two-factor.proof', 'app');
        }

        $owner->confirm($request, $client, 'two_factor_');

        $client->forceFill([
            'two_factor_method' => Client::TWO_FACTOR_APP,
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();
        $this->rememberSetup($request, $client);

        return $this->toAccount();
    }

    public function confirmApp(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $client = $request->user('web');

        // A setup this session did not start, or one left unfinished for too long, starts again.
        if (! self::isSettingUpApp($request, $client)) {
            return back()->with('error', __('Setting up two-factor sign-in took too long. Please start again.'))->withFragment('two-factor');
        }

        if (! Totp::verify((string) $client->two_factor_secret, $request->input('code'))) {
            return back()->withErrors(['code' => __('That code is not right. Scan the QR code again and enter the newest code.')])->withFragment('two-factor');
        }

        $codes = Totp::generateRecoveryCodes();
        $client->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();
        $request->session()->forget(self::SETUP);
        Activity::log('client.two_factor_on', "{$client->name} turned on two-factor sign-in (app)", $client, $client, $client);

        return back()->with('status', __('Two-factor sign-in is on.'))->with('recovery_codes', $codes)->withFragment('two-factor');
    }

    public function startEmail(Request $request, EmailCode $codes, OwnerCheck $owner): RedirectResponse
    {
        $client = $request->user('web');

        if (! $this->allows(Client::TWO_FACTOR_EMAIL) || $client->hasTwoFactorEnabled()) {
            return back();
        }

        // Clients without a password prove it is them with the code this step emails. Asking for a
        // new code in a setup this session started needs no password again; an older setup starts
        // again from the choice of app or email, where the password field is.
        if ($client->has_password && ! self::startedSetup($request, $client)) {
            $request->session()->forget('email_code.setup');

            if ($this->sentNoProof($request, $client)) {
                return redirect()->route('client.account.two-factor.proof', 'email');
            }

            $owner->confirm($request, $client, 'two_factor_');
            $this->rememberSetup($request, $client);
        }

        if ($codes->locked($client, 'setup')) {
            return $this->toAccount()->with('error', EmailCode::lockedMessage());
        }

        $codes->send($client, 'setup');

        return $this->toAccount()->with('status', __('We sent a code to :email. Enter it below.', ['email' => $client->email]));
    }

    public function confirmEmail(Request $request, EmailCode $codes): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $client = $request->user('web');

        if (! $this->allows(Client::TWO_FACTOR_EMAIL) || $client->hasTwoFactorEnabled() || ! $codes->check($client, 'setup', $request->input('code'))) {
            return back()->withErrors(['code' => $codes->locked($client, 'setup')
                ? EmailCode::lockedMessage()
                : __('That code is not right, or it is too old. Ask for a new code.')])->withFragment('two-factor');
        }

        $client->forceFill([
            'two_factor_method' => Client::TWO_FACTOR_EMAIL,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => now(),
        ])->save();
        $request->session()->forget(self::SETUP);
        Activity::log('client.two_factor_on', "{$client->name} turned on two-factor sign-in (email)", $client, $client, $client);

        return back()->with('status', __('Two-factor sign-in is on. We will email you a code each time you sign in.'))->withFragment('two-factor');
    }

    /**
     * Whether this session started an app setup that can still be finished. The Account page shows
     * the QR code and the key only then, so a setup left behind by another session, or by a version
     * that asked for no proof, is neither shown nor finished: it starts again.
     */
    public static function isSettingUpApp(Request $request, Client $client): bool
    {
        return self::startedSetup($request, $client)
            && $client->two_factor_method === Client::TWO_FACTOR_APP
            && $client->two_factor_confirmed_at === null
            && $client->two_factor_secret !== null;
    }

    private static function startedSetup(Request $request, Client $client): bool
    {
        $setup = $request->session()->get(self::SETUP);

        return is_array($setup) && ($setup['id'] ?? null) === $client->id && ($setup['expires_at'] ?? 0) >= now()->timestamp;
    }

    /**
     * Whether the form sent no proof field at all, as the forms of a theme made before the proof
     * was needed do. An empty field is a mistake in the form and is answered there.
     */
    private function sentNoProof(Request $request, Client $client): bool
    {
        return ! $request->has($client->has_password ? 'two_factor_current_password' : 'two_factor_email_code');
    }

    private function toAccount(): RedirectResponse
    {
        return redirect()->to(route('client.account.edit').'#two-factor');
    }

    private function rememberSetup(Request $request, Client $client): void
    {
        $request->session()->put(self::SETUP, ['id' => $client->id, 'expires_at' => now()->addMinutes(self::SETUP_MINUTES)->timestamp]);
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
        $request->session()->forget(self::SETUP);

        return back()->with('status', __('Two-factor sign-in is off.'))->withFragment('two-factor');
    }

    private function allows(string $method): bool
    {
        return setting('security.client_two_factor') !== 'off'
            && in_array($method, (array) setting('security.client_two_factor_methods'), true);
    }
}
