<?php

namespace App\Http\Controllers\Client\Auth;

use App\Auth\LegacyPassword;
use App\Auth\Social\SocialLogin;
use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Security\SignInLimiter;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(Request $request, SocialLogin $social): View
    {
        self::rememberReturnPath($request);

        return view('theme::auth.login', ['socialProviders' => $social->enabled()]);
    }

    /**
     * Sign-in links may carry ?return=/store/hosting/starter, so an order form can send a client to
     * sign in and back to the same order. Only a path on this site is kept, never another address.
     */
    public static function rememberReturnPath(Request $request): void
    {
        $path = $request->query('return');

        if (! is_string($path) || strlen($path) > 500 || ! preg_match('#^/(?![/\\\\])[^\s\\\\\x00-\x1f\x7f]*$#', $path)) {
            return;
        }

        $request->session()->put('url.intended', url($path));
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Emails are saved in lowercase, so "Raz@Example.com" finds the same account.
        $credentials['email'] = Str::lower(trim($credentials['email']));

        // One check at a time per email address, so guesses sent at the same moment are all counted.
        return SignInLimiter::onePasswordAtATime('client', $credentials['email'], fn (): RedirectResponse => $this->attempt($request, $credentials));
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     */
    private function attempt(Request $request, array $credentials): RedirectResponse
    {
        if (SignInLimiter::passwordLocked('client', $credentials['email'])) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('Too many tries. Wait 15 minutes, then try again.')]);
        }

        $guard = Auth::guard('web');

        if ($guard->validate($credentials)) {
            /** @var Client $client */
            $client = $guard->getLastAttempted();
        } elseif (($client = $this->legacyClient($credentials['email'], $credentials['password'])) === null) {
            SignInLimiter::passwordFailed('client', $credentials['email']);

            return back()->withInput($request->only('email'))->withErrors(['email' => __('The email or password is wrong.')]);
        }

        SignInLimiter::passwordPassed('client', $credentials['email']);

        if ($client->status === ClientStatus::Closed) {
            return back()->withErrors(['email' => __('This account is closed. Contact support if you need help.')]);
        }

        if (config('hashing.rehash_on_login', true)) {
            $guard->getProvider()->rehashPasswordIfRequired($client, $credentials);
        }

        return self::signIn($request, $client, $request->boolean('remember'), 'to the client area');
    }

    /**
     * A client imported with a password hash from their old billing system signs in with that
     * password once; it is then saved with Nuvabill's own hash and the old one is forgotten.
     */
    private function legacyClient(string $email, string $password): ?Client
    {
        $client = Client::query()->where('email', strtolower($email))->whereNotNull('legacy_password')->first();

        if ($client === null || ! LegacyPassword::check((string) $client->legacy_password, $password)) {
            return null;
        }

        $client->forceFill(['password' => $password, 'legacy_password' => null])->save();
        Activity::log('client.password_upgraded', 'Imported password replaced by a Nuvabill password at first sign-in', $client, actor: $client);

        return $client;
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
     *
     * Clients who turned it on are always asked, even when staff set client two-factor to Off:
     * Off only stops new clients from setting it up, it never quietly removes protection.
     */
    public static function signIn(Request $request, Client $client, bool $remember, string $how, ?string $default = null): RedirectResponse
    {
        if ($client->hasTwoFactorEnabled()) {
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
