<?php

namespace App\Http\Controllers\Client\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Security\EmailCode;
use App\Security\SignInLimiter;
use App\Security\Totp;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Second sign-in step for clients with two-factor sign-in turned on.
 *
 * After a few wrong codes the account has to wait (SignInLimiter), whichever IP addresses the codes
 * come from, and the sign-in starts again from the password.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request, EmailCode $codes): View|RedirectResponse
    {
        $client = $this->pendingClient($request);

        if ($client === null) {
            return redirect()->route('client.login');
        }

        if ($client->two_factor_method === Client::TWO_FACTOR_EMAIL && ! $codes->isPending($client, 'login')) {
            $codes->send($client, 'login');
        }

        return view('theme::auth.two-factor', [
            'method' => $client->two_factor_method,
            'email' => self::maskEmail($client->email),
        ]);
    }

    public function store(Request $request, EmailCode $codes): RedirectResponse
    {
        $client = $this->pendingClient($request);

        if ($client === null) {
            return redirect()->route('client.login')->withErrors(['email' => __('That took too long. Sign in again.')]);
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        // One check at a time per account, so codes sent at the same moment are all counted.
        return SignInLimiter::oneCodeAtATime('client', $client->id, fn (): RedirectResponse => $this->attempt($request, $client, $codes));
    }

    private function attempt(Request $request, Client $client, EmailCode $codes): RedirectResponse
    {
        if (SignInLimiter::codesLocked('client', $client->id)) {
            return $this->stop($request);
        }

        if (! $this->passes($client, (string) $request->input('code'), (string) $request->input('recovery_code'), $codes)) {
            Activity::log('client.two_factor_failed', "Wrong two-factor code for {$client->name}", $client, $client);

            if (SignInLimiter::codeFailed('client', $client->id)) {
                Activity::log('client.two_factor_locked', "Two-factor sign-in for {$client->name} paused for 15 minutes after too many wrong codes", $client, $client);

                return $this->stop($request);
            }

            return back()->withErrors(['code' => $client->two_factor_method === Client::TWO_FACTOR_EMAIL
                ? __('That code is not right, or it is too old. Ask for a new code.')
                : __('That code is not right. Check the time on your phone and try the newest code.')]);
        }

        SignInLimiter::codePassed('client', $client->id);
        $pending = $request->session()->pull('client.two_factor');

        return LoginController::completeLogin($request, $client, (bool) $pending['remember'], (string) $pending['how'], $pending['default'] ?? null);
    }

    public function resend(Request $request, EmailCode $codes): RedirectResponse
    {
        $client = $this->pendingClient($request);

        if ($client === null || $client->two_factor_method !== Client::TWO_FACTOR_EMAIL) {
            return redirect()->route('client.login');
        }

        return $codes->send($client, 'login')
            ? back()->with('status', __('We sent a new code.'))
            : back()->with('error', __('Please wait a minute before asking for a new code.'));
    }

    /**
     * "raz@example.com" becomes "r***@example.com".
     */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    /**
     * Too many wrong codes: forget the half-done sign-in and its email code, so it starts again
     * from the password.
     */
    private function stop(Request $request): RedirectResponse
    {
        $request->session()->forget(['client.two_factor', 'email_code.login']);

        return redirect()->route('client.login')->withErrors(['email' => __('Too many wrong codes. Wait 15 minutes, then sign in again.')]);
    }

    private function passes(Client $client, string $code, string $recoveryCode, EmailCode $codes): bool
    {
        // The same digits with spaces or tabs inside are the same code.
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code !== '') {
            if ($client->two_factor_method === Client::TWO_FACTOR_EMAIL) {
                return $codes->check($client, 'login', $code);
            }

            // Cache::add only works once per code, even for two requests at the same moment, so a
            // code that was used cannot be used again.
            return Totp::verify((string) $client->two_factor_secret, $code)
                && Cache::add("client.2fa.used.{$client->id}.{$code}", true, now()->addMinutes(2));
        }

        $recoveryCode = strtolower(trim($recoveryCode));

        if ($recoveryCode === '') {
            return false;
        }

        // Read and remove the code in one locked step, so one recovery code cannot be used twice.
        return DB::transaction(function () use ($client, $recoveryCode): bool {
            $fresh = Client::query()->lockForUpdate()->find($client->id);
            $recoveryCodes = $fresh?->two_factor_recovery_codes ?? [];

            if (! in_array($recoveryCode, $recoveryCodes, true)) {
                return false;
            }

            $fresh->forceFill(['two_factor_recovery_codes' => array_values(array_diff($recoveryCodes, [$recoveryCode]))])->save();

            return true;
        });
    }

    private function pendingClient(Request $request): ?Client
    {
        $pending = $request->session()->get('client.two_factor');

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        $client = Client::query()->find($pending['id'] ?? null);

        return $client?->hasTwoFactorEnabled() ? $client : null;
    }
}
