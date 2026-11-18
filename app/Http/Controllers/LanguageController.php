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

        return redirect()->to($this->previousWithoutLanguage($request));
    }

    /**
     * The page the visitor came from, without ?lang= (it would win over the new choice). Only an
     * address on this site is used; anything else goes to the store.
     */
    private function previousWithoutLanguage(Request $request): string
    {
        $parts = parse_url(url()->previous());

        if (! is_array($parts) || ! isset($parts['host']) || strcasecmp($parts['host'], $request->getHost()) !== 0) {
            return route('store.index');
        }

        parse_str($parts['query'] ?? '', $query);
        unset($query['lang']);
        $query = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $scheme = in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ? strtolower($parts['scheme']) : $request->getScheme();

        return $scheme.'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            .'/'.ltrim($parts['path'] ?? '', '/').($query === '' ? '' : '?'.$query);
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
