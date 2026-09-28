<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class LoginController extends Controller
{
    public function create(): View
    {
        return view('admin.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = Admin::query()->where('email', $credentials['email'])->first();

        if ($admin === null || ! Hash::check($credentials['password'], $admin->password)) {
            // What was typed is not logged: people sometimes type their password into the email box.
            Activity::log('admin.login_failed', $admin ? "Failed sign-in for {$admin->name}" : 'Failed staff sign-in for an unknown email address', $admin);

            return back()->withInput($request->only('email'))->withErrors(['email' => __('The email or password is wrong.')]);
        }

        if (! $admin->is_active) {
            return back()->withInput($request->only('email'))->withErrors(['email' => __('Your staff account is turned off.')]);
        }

        if ($admin->hasTwoFactorEnabled()) {
            $request->session()->put('admin.two_factor', [
                'id' => $admin->id,
                'remember' => $request->boolean('remember'),
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]);

            return redirect()->route('admin.two-factor.challenge');
        }

        return self::completeLogin($request, $admin, $request->boolean('remember'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('admin')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }

    /**
     * Sign the staff member in after every check has passed.
     */
    public static function completeLogin(Request $request, Admin $admin, bool $remember): RedirectResponse
    {
        Auth::guard('admin')->login($admin, $remember);
        $request->session()->regenerate();

        $admin->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Activity::log('admin.login', "{$admin->name} signed in", actor: $admin);

        return redirect()->intended(route('admin.dashboard'));
    }
}
