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
use Illuminate\Support\Timebox;
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
        $typed = trim($credentials['email']);
        $credentials['email'] = Str::lower($typed);

        // One check at a time per email address, so guesses sent at the same moment are all counted.
        return SignInLimiter::onePasswordAtATime('client', $credentials['email'], fn (): RedirectResponse => $this->attempt($request, $credentials, $typed));
    }

    /**
     * @param  array{email: string, password: string}  $credentials
     */
    private function attempt(Request $request, array $credentials, string $typed): RedirectResponse
    {
        if (($refused = SignInLimiter::passwordRefused($request, 'client', $credentials['email'])) !== null) {
            return back()->withInput($request->only('email'))->withErrors(['email' => $refused]);
        }

        $client = $this->clientWithPassword($credentials['email'], $credentials['password'])
            ?? $this->legacyClient($credentials['email'], $credentials['password'])
            ?? $this->caseTwin($typed, $credentials);

        if ($client === null) {
            SignInLimiter::passwordFailed($request, 'client', $credentials['email']);

            return back()->withInput($request->only('email'))->withErrors(['email' => __('The email or password is wrong.')]);
        }

        SignInLimiter::passwordPassed($request, 'client', $credentials['email']);

        if ($client->status === ClientStatus::Closed) {
            return back()->withErrors(['email' => __('This account is closed. Contact support if you need help.')]);
        }

        if (config('hashing.rehash_on_login', true)) {
            Auth::guard('web')->getProvider()->rehashPasswordIfRequired($client, $credentials);
        }

        return self::signIn($request, $client, $request->boolean('remember'), 'to the client area');
    }

    /**
     * The client with this email and password, or null.
     *
     * MySQL and MariaDB also find "owner@example.test" for "öwner@example.test". Every count in
     * SignInLimiter is kept per address typed, so such a spelling is treated as an unknown email and
     * its password is never checked. Like Laravel's own check, a wrong answer takes at least as long
     * whether or not the email has an account, so the timing gives no account away.
     */
    private function clientWithPassword(string $email, string $password): ?Client
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($email, $password): ?Client {
            $provider = Auth::guard('web')->getProvider();
            $client = $provider->retrieveByCredentials(['email' => $email]);

            if (! $client instanceof Client
                || ! SignInLimiter::sameEmail($email, (string) $client->email)
                || ! $provider->validateCredentials($client, ['password' => $password])) {
                return null;
            }

            $timebox->returnEarly();

            return $client;
        }, (int) config('auth.timebox_duration', 200000));
    }

    /**
     * A client imported with a password hash from their old billing system signs in with that
     * password once; it is then saved with Nuvabill's own hash and the old one is forgotten.
     */
    private function legacyClient(string $email, string $password): ?Client
    {
        $client = Client::query()->where('email', Str::lower($email))->whereNotNull('legacy_password')->first();

        if ($client === null
            || ! SignInLimiter::sameEmail($email, (string) $client->email)
            || ! LegacyPassword::check((string) $client->legacy_password, $password)) {
            return null;
        }

        $client->forceFill(['password' => $password, 'legacy_password' => null])->save();
        Activity::log('client.password_upgraded', 'Imported password replaced by a Nuvabill password at first sign-in', $client, actor: $client);

        return $client;
    }

    /**
     * Before emails were saved in lowercase, a site on SQLite (which compares letter case) could get
     * two clients for one mailbox, such as "Raz@Example.com" and "raz@example.com"; site health lists
     * them. Until staff merge them, the one with capitals still signs in when its own spelling is
     * typed, with its own password. Both spellings share one count of wrong passwords.
     *
     * @param  array{email: string, password: string}  $credentials
     */
    private function caseTwin(string $typed, array $credentials): ?Client
    {
        if ($typed === $credentials['email']) {
            return null;
        }

        return $this->clientWithPassword($typed, $credentials['password']);
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
