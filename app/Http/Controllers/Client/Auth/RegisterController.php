<?php

namespace App\Http\Controllers\Client\Auth;

use App\Auth\ClientRegistrar;
use App\Auth\Social\SocialLogin;
use App\Http\Controllers\Controller;
use App\Support\Countries;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class RegisterController extends Controller
{
    public function create(SocialLogin $social): View
    {
        return view('theme::auth.register', ['countries' => Countries::all(), 'socialProviders' => $social->enabled()]);
    }

    public function store(Request $request, ClientRegistrar $registrar): RedirectResponse
    {
        $client = $registrar->register($request->validate(ClientRegistrar::rules()));

        Auth::guard('web')->login($client);
        $request->session()->regenerate();

        return redirect()->intended(route('client.dashboard'));
    }
}
