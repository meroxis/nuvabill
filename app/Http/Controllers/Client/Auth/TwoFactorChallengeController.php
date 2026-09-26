<?php

namespace App\Http\Controllers\Client\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Security\EmailCode;
use App\Security\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Second sign-in step for clients with two-factor sign-in turned on.
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

        if (! $this->passes($client, (string) $request->input('code'), (string) $request->input('recovery_code'), $codes)) {
            return back()->withErrors(['code' => $client->two_factor_method === Client::TWO_FACTOR_EMAIL
                ? __('That code is not right, or it is too old. Ask for a new code.')
                : __('That code is not right. Check the time on your phone and try the newest code.')]);
        }

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
     * "dana@example.com" becomes "d***@example.com".
     */
    public static function maskEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1).'***@'.$domain;
    }

    private function passes(Client $client, string $code, string $recoveryCode, EmailCode $codes): bool
    {
        if ($code !== '') {
            if ($client->two_factor_method === Client::TWO_FACTOR_EMAIL) {
                return $codes->check($client, 'login', $code);
            }

            $cacheKey = "client.2fa.used.{$client->id}.{$code}";

            if (Cache::has($cacheKey) || ! Totp::verify((string) $client->two_factor_secret, $code)) {
                return false;
            }

            Cache::put($cacheKey, true, now()->addMinutes(2));

            return true;
        }

        $recoveryCode = strtolower(trim($recoveryCode));
        $recoveryCodes = $client->two_factor_recovery_codes ?? [];

        if ($recoveryCode === '' || ! in_array($recoveryCode, $recoveryCodes, true)) {
            return false;
        }

        $client->forceFill(['two_factor_recovery_codes' => array_values(array_diff($recoveryCodes, [$recoveryCode]))])->save();

        return true;
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
