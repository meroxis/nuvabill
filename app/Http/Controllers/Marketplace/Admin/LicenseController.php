<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\LicenseService;
use App\Models\License;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Staff find licenses, see where a key is used, and revoke keys that leaked.
 */
class LicenseController extends Controller
{
    public function index(Request $request): View
    {
        $term = trim($request->string('q')->toString());

        return view('admin.store.licenses', [
            'licenses' => License::query()
                ->with('item', 'client')
                ->when($term !== '', fn ($query) => $query->where(fn ($query) => $query
                    ->where('key', 'like', '%'.strtoupper($term).'%')
                    ->orWhere('site', 'like', "%{$term}%")
                    ->orWhereHas('client', fn ($query) => $query->where('email', 'like', "%{$term}%"))))
                ->latest('id')
                ->paginate(30)
                ->withQueryString(),
            'term' => $term,
        ]);
    }

    public function show(License $license, LicenseService $licenses): View
    {
        return view('admin.store.license', [
            'license' => $license->load('item', 'client', 'service'),
            'recentSites' => $licenses->recentSites($license),
            'shared' => $licenses->looksShared($license),
            'checks' => $license->checks()->latest('id')->limit(25)->get(),
        ]);
    }

    public function revoke(Request $request, License $license): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:255']])['reason'];
        $license->update(['status' => License::STATUS_REVOKED, 'revoked_reason' => $reason]);
        Activity::log('license.revoked', "License {$license->publicId()} revoked: {$reason}", $license->service, $license->client);

        return back()->with('status', __('License revoked. Sites using it get no more downloads and show it as unlicensed.'));
    }

    public function restore(License $license): RedirectResponse
    {
        $license->update(['status' => License::STATUS_ACTIVE, 'revoked_reason' => null]);
        Activity::log('license.restored', "License {$license->publicId()} restored", $license->service, $license->client);

        return back()->with('status', __('License active again.'));
    }

    public function release(License $license): RedirectResponse
    {
        $license->update(['site' => null]);
        Activity::log('license.released', "License {$license->publicId()} released from its site by staff", $license->service, $license->client);

        return back()->with('status', __('The key is free and will tie to the next site it is used on.'));
    }
}
