<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\WhiteLabel;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Settings → License: the White-label license that removes the "Powered by Nuvabill" credit.
 */
class WhiteLabelController extends Controller
{
    public function edit(WhiteLabel $whiteLabel): View
    {
        return view('admin.settings.license', [
            'hasKey' => $whiteLabel->key() !== '',
            'maskedKey' => $whiteLabel->key() !== '' ? substr($whiteLabel->key(), 0, 8).'…'.substr($whiteLabel->key(), -4) : null,
            'state' => $whiteLabel->state(),
            'active' => $whiteLabel->isActive(),
            'buyUrl' => rtrim((string) config('nuvabill.marketplace.url'), '/').'/white-label',
        ]);
    }

    public function update(Request $request, WhiteLabel $whiteLabel): RedirectResponse
    {
        $data = $request->validate(['key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]*$/']]);
        $state = $whiteLabel->saveKey((string) ($data['key'] ?? ''));

        if ($state === []) {
            return back()->with('status', __('License key removed. The Nuvabill credit shows again.'));
        }

        return back()->with(($state['valid'] ?? false) ? 'status' : 'error', ($state['valid'] ?? false) ? __('Your White-label license is active. The Nuvabill credit is hidden.') : ($state['message'] ?: __('This key is not valid.')));
    }

    public function check(WhiteLabel $whiteLabel): RedirectResponse
    {
        $state = $whiteLabel->check();

        return back()->with(($state['valid'] ?? false) ? 'status' : 'error', ($state['valid'] ?? false) ? __('Your White-label license is active.') : ($state['message'] ?? __('This key is not valid.')));
    }
}
