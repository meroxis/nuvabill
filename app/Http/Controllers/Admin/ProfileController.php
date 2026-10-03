<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Push\WebPush;
use App\Security\Totp;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
            'newApiKey' => session('new_api_key'),
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
        ]);

        // The guard's own copy gets the new password, so this device stays signed in while every
        // other session and remember-me cookie stops working (the auth.session middleware).
        $request->user('admin')->forceFill(['password' => $request->input('password'), 'remember_token' => Str::random(60)])->save();
        $request->session()->regenerate();
        Activity::log('admin.password', 'Changed own password');

        return back()->with('status', __('Password changed.'));
    }

    public function startTwoFactor(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');

        if ($admin->hasTwoFactorEnabled()) {
            return back();
        }

        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => null])->save();

        return back();
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $admin = $request->user('admin');

        if ($admin->two_factor_secret === null || ! Totp::verify($admin->two_factor_secret, $request->input('code'))) {
            return back()->withErrors(['code' => __('That code is not right. Scan the QR code again and enter the newest code.')]);
        }

        $codes = Totp::generateRecoveryCodes();
        $admin->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $codes,
        ])->save();

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
