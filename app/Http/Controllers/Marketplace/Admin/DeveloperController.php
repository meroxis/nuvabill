<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Models\Developer;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff manage developers: verify them, set a special share, or suspend them.
 */
class DeveloperController extends Controller
{
    public function index(): View
    {
        return view('admin.store.developers', [
            'developers' => Developer::query()->with('client')->withCount('items')->orderByDesc('is_official')->orderBy('name')->paginate(30),
            'currency' => (string) setting('billing.currency'),
            'share' => (int) setting('marketplace.developer_share', 83),
        ]);
    }

    public function edit(Developer $developer): View
    {
        return view('admin.store.developer-form', [
            'developer' => $developer->load('client', 'items'),
            'currency' => (string) setting('billing.currency'),
            'share' => (int) setting('marketplace.developer_share', 83),
        ]);
    }

    public function update(Request $request, Developer $developer): RedirectResponse
    {
        $data = $request->validate([
            'is_verified' => ['boolean'],
            'is_official' => ['boolean'],
            'share_percent' => ['nullable', 'integer', 'between:0,100'],
            'status' => ['required', Rule::in([Developer::STATUS_ACTIVE, Developer::STATUS_SUSPENDED])],
        ]);

        $developer->update([
            'is_verified' => $request->boolean('is_verified'),
            'is_official' => $request->boolean('is_official'),
            'share_percent' => $data['share_percent'] ?? null,
            'status' => $data['status'],
        ]);

        Activity::log('developer.updated', "Developer {$developer->name} saved (share {$developer->share()}%, {$developer->status})");

        return redirect()->route('admin.store.developers.index')->with('status', __(':name saved.', ['name' => $developer->name]));
    }
}
