<?php

namespace App\Http\Controllers;

use App\Http\Middleware\ApplyThemePreview;
use App\Support\Themes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Start or stop previewing a theme or order form in this browser session.
 */
class PreviewController extends Controller
{
    public function start(Request $request, Themes $themes, string $kind, string $slug): RedirectResponse
    {
        abort_unless(ApplyThemePreview::allowed(), 404);

        $exists = $kind === 'theme' ? $themes->exists($slug) : ($slug === Themes::STANDARD_ORDER_FORM || $themes->orderFormExists($slug));
        abort_unless($exists, 404);

        $preview = (array) $request->session()->get(ApplyThemePreview::SESSION_KEY, []);
        $preview[$kind === 'theme' ? 'theme' : 'orderform'] = $slug;
        $request->session()->put(ApplyThemePreview::SESSION_KEY, $preview);

        if ($kind === 'theme' && auth('web')->check()) {
            return redirect()->route('client.dashboard');
        }

        return redirect()->route('store.index');
    }

    public function stop(Request $request): RedirectResponse
    {
        $request->session()->forget(ApplyThemePreview::SESSION_KEY);

        return auth('admin')->check()
            ? redirect()->route('admin.marketplace.index')
            : redirect()->route('store.index');
    }
}
