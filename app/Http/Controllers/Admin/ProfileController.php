<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Push\WebPush;
use App\Security\StaffSecondFactor;
use App\Security\Totp;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * A staff member's own account: password, two-factor login, passkeys, API keys and the phone app.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $admin = $request->user('admin');
        $settingUp = $admin->two_factor_secret !== null && $admin->two_factor_confirmed_at === null;

        return view('admin.profile', [
            'admin' => $admin,
            'settingUp' => $settingUp,
            'qrCode' => $settingUp
                ? Totp::qrCodeSvg(Totp::provisioningUri((string) $admin->two_factor_secret, $admin->email, (string) setting('company.name')))
                : null,
            'recoveryCodes' => session('recovery_codes'),
            'passkeys' => $admin->passkeys()->latest('id')->get(),
            'apiTokens' => $admin->apiTokens()->latest('id')->get(),
            'newApiKey' => is_string($key = session('new_api_key')) ? rescue(fn (): string => Crypt::decryptString($key), null, report: false) : null,
            'pushDevices' => $admin->pushSubscriptions()->latest('id')->get(),
            'pushAlerts' => $admin->pushAlerts(),
            'pushKey' => rescue(fn (): string => app(WebPush::class)->publicKey(), '', report: false),
            'appQrCode' => Totp::qrCodeSvg(route('admin.today')),
        ]);
    }

    public function password(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:admin'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'revoke_api_keys' => ['sometimes', 'boolean'],
            'revoke_passkeys' => ['sometimes', 'boolean'],
        ]);
        $admin = $request->user('admin');

        // The guard's own copy gets the new password, so this device stays signed in while every
        // other session and remember-me cookie stops working (the auth.session middleware).
        $admin->forceFill(['password' => $request->input('password'), 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        Activity::log('admin.password', 'Changed own password');

        // API keys do not end with the sessions. The box is ticked by default; untick it to keep
        // a working integration when the password is changed on purpose.
        $keys = $request->boolean('revoke_api_keys', true) && $admin->revokeApiKeys('own password changed') > 0;

        // Passkeys and phone alert devices do not end with the sessions either. Their box is ticked
        // by default too; untick it to keep them when nobody else could have added one.
        $signIns = $request->boolean('revoke_passkeys', true)
            && $admin->revokePasskeys('own password changed') + $admin->revokePushDevices('own password changed') > 0;

        return back()->with('status', match (true) {
            $keys && $signIns => __('Password changed. Your API keys, passkeys and phone alerts were removed.'),
            $keys => __('Password changed. Your API keys were deleted.'),
            $signIns => __('Password changed. Your passkeys and phone alerts were removed.'),
            default => __('Password changed.'),
        });
    }

    /**
     * Setting up and turning on two-factor login both ask for the password, so a stolen session
     * cannot add its own authenticator app and pass the two-factor check of server module tools.
     */
    public function startTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:admin']]);
        $admin = $request->user('admin');

        if ($admin->hasTwoFactorEnabled()) {
            return back();
        }

        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => null])->save();

        return back();
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password:admin'],
            'code' => ['required', 'string', 'max:20'],
        ]);
        $admin = $request->user('admin');
        $code = preg_replace('/\s+/', '', (string) $request->input('code')) ?? '';

        if ($admin->hasTwoFactorEnabled()) {
            return back();
        }

        // Each code works once, like at sign-in.
        if ($admin->two_factor_secret === null || ! Totp::verify($admin->two_factor_secret, $code)
            || ! Cache::add("admin.2fa.used.{$admin->id}.{$code}", true, now()->addMinutes(2))) {
            return back()->withErrors(['code' => __('That code is not right. Scan the QR code again and enter the newest code.')]);
        }

        $codes = Totp::generateRecoveryCodes();
        $admin->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

        // This session just passed a fresh code: it may use server module tools (StaffSecondFactor).
        $request->session()->regenerate();
        StaffSecondFactor::mark($admin);

        Activity::log('admin.two_factor_on', 'Turned on two-factor login');

        return back()->with('status', __('Two-factor login is on.'))->with('recovery_codes', $codes);
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password:admin']]);

        $request->user('admin')->forceFill([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        Activity::log('admin.two_factor_off', 'Turned off two-factor login');

        return back()->with('status', __('Two-factor login is off.'));
    }
}
