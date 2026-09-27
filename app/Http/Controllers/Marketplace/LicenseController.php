<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\LicenseService;
use App\Models\License;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Clients free their license key from its site, to use it on a new one.
 */
class LicenseController extends Controller
{
    public function move(Request $request, License $license, LicenseService $licenses): RedirectResponse
    {
        abort_unless($license->client_id === $request->user('web')->id, 404);

        return $licenses->moveToNewSite($license)
            ? back()->with('status', __('The key is free. It is tied to the next site you install with.'))
            : back()->with('error', __('This key was moved :count times this year. Open a ticket and we will move it for you.', ['count' => License::SITE_CHANGES_PER_YEAR]));
    }
}
