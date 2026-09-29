<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\BrandIcon;
use App\Support\WhiteLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → General → Your icon: upload or remove the site's own icon. Only sites with a valid
 * White-label License may use their own icon.
 */
class BrandIconController extends Controller
{
    public function store(Request $request, WhiteLabel $whiteLabel): RedirectResponse
    {
        if (! $whiteLabel->isActive()) {
            return back()->withErrors(['icon' => __('Your own icon needs a White-label License.')]);
        }

        $request->validate([
            'icon' => ['required', 'file', 'mimes:png', 'mimetypes:image/png', 'max:2048', 'dimensions:min_width='.BrandIcon::MIN_SIZE.',ratio=1'],
        ], [
            'icon.dimensions' => __('Use a square PNG of at least :size × :size pixels.', ['size' => BrandIcon::MIN_SIZE]),
        ]);

        BrandIcon::store($request->file('icon'));
        Activity::log('settings.icon', 'Site icon changed');

        return back()->with('status', __('Your icon is saved. Browsers may take a moment to show the new tab icon.'));
    }

    public function destroy(): RedirectResponse
    {
        BrandIcon::remove();
        Activity::log('settings.icon', 'Site icon removed');

        return back()->with('status', __('Your icon was removed. The site uses the Nuvabill icon again.'));
    }
}
