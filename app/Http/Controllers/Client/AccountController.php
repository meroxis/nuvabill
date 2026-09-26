<?php

namespace App\Http\Controllers\Client;

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
    public function edit(Request $request): View
    {
        return view('theme::client.account', [
            'client' => $request->user('web'),
            'countries' => Countries::all(),
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
        $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $request->user('web')->update(['password' => $request->input('password')]);

        return back()->with('status', __('Password changed.'));
    }
}
