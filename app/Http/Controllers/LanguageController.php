<?php

namespace App\Http\Controllers;

use App\Support\Demo;
use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language switcher. The choice is kept for this browser and saved on the account of
 * whoever is signed in, so it follows them to other devices. The shared demo accounts only
 * keep it for this browser.
 */
class LanguageController extends Controller
{
    public function client(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(Locales::enabled()))]]);

        $request->session()->put(Locales::SESSION_CLIENT, $data['locale']);

        if (! Demo::isEnabled()) {
            $request->user('web')?->forceFill(['language' => $data['locale']])->save();
        }

        return back();
    }

    public function admin(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(array_keys(Locales::ALL))]]);

        $request->session()->put(Locales::SESSION_ADMIN, $data['locale']);

        if (! Demo::isEnabled()) {
            $request->user('admin')->forceFill(['language' => $data['locale']])->save();
        }

        return back();
    }
}
