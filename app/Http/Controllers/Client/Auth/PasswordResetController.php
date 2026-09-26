<?php

namespace App\Http\Controllers\Client\Auth;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class PasswordResetController extends Controller
{
    public function request(): View
    {
        return view('theme::auth.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::broker('clients')->sendResetLink($request->only('email'));

        return back()->with('status', __('If an account uses that email, we sent a link to reset the password.'));
    }

    public function edit(Request $request, string $token): View
    {
        return view('theme::auth.reset-password', ['token' => $token, 'email' => $request->query('email')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)],
        ]);

        $status = Password::broker('clients')->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Client $client, string $password): void {
                $client->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                event(new PasswordReset($client));
            },
        );

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('client.login')->with('status', __('Your password was changed. Sign in with the new one.'))
            : back()->withInput($request->only('email'))->withErrors(['email' => __($status)]);
    }
}
