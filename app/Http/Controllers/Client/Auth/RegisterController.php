<?php

namespace App\Http\Controllers\Client\Auth;

use App\Enums\ClientStatus;
use App\Http\Controllers\Controller;
use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Support\Activity;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(): View
    {
        return view('theme::auth.register', ['countries' => Countries::all()]);
    }

    public function store(Request $request, TemplateMailer $mailer): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', 'unique:clients,email'],
            'phone' => ['nullable', 'string', 'max:40'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $client = Client::create($data + [
            'currency' => setting('billing.currency'),
            'status' => ClientStatus::Active,
        ]);

        Auth::guard('web')->login($client);
        $request->session()->regenerate();

        Activity::log('client.registered', "{$client->name} created an account", $client, $client, $client);
        $mailer->send('client.welcome', $client, ['login_url' => route('client.login'), 'reset_url' => route('client.password.request')]);

        return redirect()->intended(route('client.dashboard'));
    }
}
