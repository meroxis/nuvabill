<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Security\SignInLimiter;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

use function Illuminate\Support\defer;

class PasswordResetController extends Controller
{
    /**
     * Every "forgot password" answer takes at least this long (in microseconds), whether or not the
     * email belongs to a staff account, so staff emails cannot be found by timing the answer.
     */
    private const ANSWER_TIME = 1_000_000;

    public function request(): View
    {
        return view('admin.auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        (new Timebox)->call(function () use ($request): void {
            Password::broker('admins')->sendResetLink($request->only('email'), function (Admin $admin, string $token): string {
                // The email goes out after the answer is sent, so a slow or broken mail server
                // does not show in the answer either. It is never queued: the plain token stays in memory.
                defer(fn () => $admin->sendPasswordResetNotification($token));

                return Password::RESET_LINK_SENT;
            });
        }, self::ANSWER_TIME);

        // Same answer whether or not the address exists, so staff emails cannot be discovered.
        return back()->with('status', __('If that email belongs to a staff account, a reset link is on its way.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('admin.auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(10)],
        ]);

        // A new password hash also ends every other signed-in session (the auth.session middleware).
        // The link proved this is the owner, so wrong passwords others typed no longer keep them out.
        $status = Password::broker('admins')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Admin $admin, string $password) use ($request): void {
                $admin->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                SignInLimiter::passwordReset($request, 'admin', $admin->email);
                event(new PasswordReset($admin));
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('admin.login')->with('status', __('Your password was changed. Sign in with the new one.'))
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }
}
