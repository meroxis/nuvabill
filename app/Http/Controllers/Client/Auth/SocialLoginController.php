<?php

namespace App\Http\Controllers\Client\Auth;

use App\Auth\Social\Provider;
use App\Auth\Social\SocialLogin;
use App\Auth\Social\SocialUser;
use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\SocialAccount;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Throwable;

/**
 * Sign in or create an account with Google, GitHub or Facebook, and connect those
 * accounts from the Account page.
 *
 * An existing account is only signed in by email when the provider says the email is verified.
 */
class SocialLoginController extends Controller
{
    public function redirect(Request $request, string $provider, SocialLogin $social): RedirectResponse
    {
        $driver = $social->provider($provider) ?? abort(404);
        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put('social_login', [
            'provider' => $provider,
            'state' => $state,
            'verifier' => $verifier,
            'client_id' => $request->user('web')?->id,
        ]);

        return redirect()->away($driver->authorizeUrl($state, $verifier));
    }

    public function callback(Request $request, string $provider, SocialLogin $social, TemplateMailer $mailer): RedirectResponse
    {
        $driver = $social->provider($provider) ?? abort(404);
        $pending = $request->session()->pull('social_login');
        $current = $request->user('web');
        $state = $request->query('state');

        if (! is_array($pending) || $pending['provider'] !== $provider || ! is_string($state) || ! hash_equals($pending['state'], $state) || $pending['client_id'] !== $current?->id) {
            return $this->failed($request, __('The sign-in link expired. Please try again.'));
        }

        if (! is_string($request->query('code')) || $request->query('code') === '') {
            return $this->failed($request, __('Sign-in with :provider was cancelled.', ['provider' => $driver->name()]));
        }

        try {
            $user = $driver->user($request->query('code'), $pending['verifier']);
        } catch (Throwable $exception) {
            report($exception);

            return $this->failed($request, __(':provider did not confirm the sign-in. Please try again.', ['provider' => $driver->name()]));
        }

        $account = SocialAccount::query()->where('provider', $provider)->where('provider_user_id', $user->id)->first();

        if ($current !== null) {
            return $this->connect($current, $account, $driver, $user);
        }

        if ($account !== null) {
            return $this->signIn($request, $account->client, $account, $driver);
        }

        if ($user->email === null) {
            return $this->failed($request, __(':provider did not share a confirmed email address. Create an account with a password instead.', ['provider' => $driver->name()]));
        }

        $client = Client::query()->whereRaw('LOWER(email) = ?', [$user->email])->first();

        if ($client !== null) {
            if (! $user->emailVerified) {
                return $this->failed($request, __('An account with this email already exists. Sign in with your password, then connect :provider on your Account page.', ['provider' => $driver->name()]));
            }

            return $this->signIn($request, $client, $this->link($client, $driver, $user), $driver);
        }

        $client = $this->register($user, $mailer, $driver);

        return $this->signIn($request, $client, $this->link($client, $driver, $user), $driver, isNew: true);
    }

    /**
     * Disconnect a provider from the signed-in client.
     */
    public function destroy(Request $request, string $provider): RedirectResponse
    {
        $client = $request->user('web');

        if (! $client->has_password && $client->socialAccounts()->where('provider', '!=', $provider)->doesntExist()) {
            return back()->with('error', __('Set a password first, so you can still sign in.'));
        }

        $client->socialAccounts()->where('provider', $provider)->delete();
        Activity::log('client.social_disconnected', "{$client->name} disconnected {$provider} sign-in", $client, $client, $client);

        return back()->with('status', __('Disconnected. You can connect it again at any time.'));
    }

    private function connect(Client $client, ?SocialAccount $account, Provider $driver, SocialUser $user): RedirectResponse
    {
        if ($account !== null && $account->client_id !== $client->id) {
            return redirect()->route('client.account.edit')->with('error', __('This :provider account is already connected to another client account.', ['provider' => $driver->name()]));
        }

        $this->link($client, $driver, $user);
        Activity::log('client.social_connected', "{$client->name} connected {$driver->name()} sign-in", $client, $client, $client);

        return redirect()->route('client.account.edit')->with('status', __(':provider is connected. You can now sign in with it.', ['provider' => $driver->name()]));
    }

    private function link(Client $client, Provider $driver, SocialUser $user): SocialAccount
    {
        return $client->socialAccounts()->updateOrCreate(
            ['provider' => $driver->slug()],
            ['provider_user_id' => $user->id, 'email' => $user->email],
        );
    }

    private function register(SocialUser $user, TemplateMailer $mailer, Provider $driver): Client
    {
        $client = Client::create([
            'first_name' => Str::limit($user->firstName ?: Str::before((string) $user->email, '@'), 100, ''),
            'last_name' => Str::limit($user->lastName, 100, ''),
            'email' => $user->email,
            'password' => Str::random(40),
            'currency' => setting('billing.currency'),
            'status' => ClientStatus::Active,
        ]);

        $client->forceFill(['has_password' => false, 'email_verified_at' => $user->emailVerified ? now() : null])->save();

        Activity::log('client.registered', "{$client->name} created an account with {$driver->name()}", $client, $client, $client);
        $mailer->send('client.welcome', $client, ['login_url' => route('client.login'), 'reset_url' => route('client.password.request')]);

        return $client;
    }

    private function signIn(Request $request, Client $client, SocialAccount $account, Provider $driver, bool $isNew = false): RedirectResponse
    {
        if ($client->status === ClientStatus::Closed) {
            return $this->failed($request, __('This account is closed. Contact support if you need help.'));
        }

        Auth::guard('web')->login($client);
        $request->session()->regenerate();
        $client->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $account->forceFill(['last_used_at' => now()])->save();
        Activity::log('client.login', "{$client->name} signed in with {$driver->name()}", $client, $client, $client);

        if ($isNew) {
            return redirect()->intended(route('client.account.edit'))->with('status', __('Welcome! Please add your country and address on your Account page. They appear on your invoices.'));
        }

        return redirect()->intended(route('client.dashboard'));
    }

    private function failed(Request $request, string $message): RedirectResponse
    {
        return redirect()->route($request->user('web') ? 'client.account.edit' : 'client.login')->with('error', $message);
    }
}
