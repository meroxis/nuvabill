<?php

namespace App\Http\Controllers\Client;

use App\Auth\Social\SocialLogin;
use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function edit(Request $request, SocialLogin $social): View
    {
        $client = $request->user('web');

        return view('theme::client.account', [
            'client' => $client,
            'countries' => Countries::all(),
            'socialProviders' => $social->enabled(),
            'socialAccounts' => $client->socialAccounts()->get()->keyBy('provider'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $client = $request->user('web');

        $client->update($request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:190', Rule::unique('clients', 'email')->ignore($client->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'address_1' => ['nullable', 'string', 'max:190'],
            'address_2' => ['nullable', 'string', 'max:190'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postcode' => ['nullable', 'string', 'max:20'],
            'country' => ['required', Rule::in(array_keys(Countries::all()))],
        ]));

        Activity::log('client.profile', 'Client updated their details', $client);

        return back()->with('status', __('Your details were saved.'));
    }

    public function password(Request $request): RedirectResponse
    {
        $client = $request->user('web');

        // Clients who signed up with Google, GitHub or Facebook choose their first password without an old one.
        $request->validate([
            'current_password' => $client->has_password ? ['required', 'current_password:web'] : ['nullable'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $client->forceFill(['password' => $request->input('password'), 'has_password' => true])->save();

        return back()->with('status', __('Password saved.'));
    }
}
