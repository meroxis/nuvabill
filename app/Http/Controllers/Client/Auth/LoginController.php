<?php

namespace App\Http\Controllers\Client\Auth;

use App\Auth\Social\SocialLogin;
use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(SocialLogin $social): View
    {
        return view('theme::auth.login', ['socialProviders' => $social->enabled()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $guard = Auth::guard('web');

        if (! $guard->validate($credentials)) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('The email or password is wrong.')]);
        }

        /** @var Client $client */
        $client = $guard->getLastAttempted();

        if ($client->status === ClientStatus::Closed) {
            return back()->withErrors(['email' => __('This account is closed. Contact support if you need help.')]);
        }

        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($client, $credentials);
        }

        return self::signIn($request, $client, $request->boolean('remember'), 'to the client area');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('store.index');
    }

    /**
     * Sign the client in after their password (or Google, GitHub, Facebook) was accepted,
     * asking for the two-factor code first when they turned it on.
     */
    public static function signIn(Request $request, Client $client, bool $remember, string $how, ?string $default = null): RedirectResponse
    {
        if ($client->hasTwoFactorEnabled() && setting('security.client_two_factor') !== 'off') {
            $request->session()->put('client.two_factor', [
                'id' => $client->id,
                'remember' => $remember,
                'how' => $how,
                'default' => $default,
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]);

            return redirect()->route('client.two-factor.challenge');
        }

        return self::completeLogin($request, $client, $remember, $how, $default);
    }

    /**
     * Sign the client in after every check has passed.
     */
    public static function completeLogin(Request $request, Client $client, bool $remember, string $how, ?string $default = null): RedirectResponse
    {
        Auth::guard('web')->login($client, $remember);
        $request->session()->regenerate();

        $client->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Activity::log('client.login', "{$client->name} signed in {$how}", $client, $client, $client);

        return redirect()->intended($default ?? route('client.dashboard'));
    }
}
