<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\TldPrice;
use App\Support\Activity;
use App\Support\Money;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Domain prices per extension, and the general domain settings.
 */
class TldPriceController extends Controller
{
    public function index(ExtensionManager $extensions): View
    {
        return view('admin.settings.tlds.index', [
            'prices' => TldPrice::query()->orderBy('currency')->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('tld')->get(),
            'registrars' => $extensions->registrarNames()->all(),
            'settings' => app(Settings::class)->all(),
            'price' => new TldPrice(['currency' => setting('billing.currency'), 'min_years' => 1, 'max_years' => 10, 'epp_required' => true, 'is_enabled' => true]),
        ]);
    }

    public function store(Request $request, ExtensionManager $extensions): RedirectResponse
    {
        $price = TldPrice::create($this->validated($request, $extensions));
        Activity::log('tld.created', "Domain extension .{$price->tld} ({$price->currency}) added");

        return redirect()->route('admin.settings.tlds.index')->with('status', __('.:tld added.', ['tld' => $price->tld]));
    }

    public function edit(TldPrice $tldPrice, ExtensionManager $extensions): View
    {
        return view('admin.settings.tlds.edit', [
            'price' => $tldPrice,
            'registrars' => $extensions->registrarNames()->all(),
        ]);
    }

    public function update(Request $request, TldPrice $tldPrice, ExtensionManager $extensions): RedirectResponse
    {
        $tldPrice->update($this->validated($request, $extensions, $tldPrice));
        Activity::log('tld.updated', "Domain extension .{$tldPrice->tld} ({$tldPrice->currency}) changed");

        return redirect()->route('admin.settings.tlds.index')->with('status', __('.:tld saved.', ['tld' => $tldPrice->tld]));
    }

    public function destroy(TldPrice $tldPrice): RedirectResponse
    {
        $tldPrice->delete();
        Activity::log('tld.deleted', "Domain extension .{$tldPrice->tld} ({$tldPrice->currency}) removed");

        return redirect()->route('admin.settings.tlds.index')->with('status', __('.:tld removed. Existing domains are not changed.', ['tld' => $tldPrice->tld]));
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'nameservers' => ['nullable', 'array', 'max:5'],
            'nameservers.*' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9.-]+$/i'],
            'auto_register' => ['boolean'],
            'renewal_days_before' => ['required', 'integer', 'between:0,90'],
            'expiry_notice_days' => ['nullable', 'string', 'max:40', 'regex:/^[\d,\s]*$/'],
        ]);

        $settings->setMany([
            'domains.nameservers' => array_values(array_filter(array_map(fn (?string $ns): string => strtolower(trim((string) $ns)), $data['nameservers'] ?? []))),
            'domains.auto_register' => $request->boolean('auto_register'),
            'domains.renewal_days_before' => (int) $data['renewal_days_before'],
            'domains.expiry_notice_days' => collect(explode(',', (string) ($data['expiry_notice_days'] ?? '')))->map(fn (string $day): int => (int) trim($day))->filter()->unique()->sortDesc()->values()->all(),
        ]);

        Activity::log('settings.updated', 'Domain settings changed');

        return back()->with('status', __('Domain settings saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ExtensionManager $extensions, ?TldPrice $current = null): array
    {
        $request->merge(['tld' => strtolower(trim((string) $request->input('tld'), " .\t")), 'currency' => strtoupper((string) $request->input('currency'))]);

        $data = $request->validate([
            'tld' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9-]+(\.[a-z0-9-]+)*$/',
                Rule::unique('tld_prices')->where('currency', $request->input('currency'))->ignore($current?->id)],
            'currency' => ['required', 'string', 'size:3'],
            'registrar' => ['nullable', Rule::in($extensions->registrarNames()->keys()->all())],
            'register_price' => ['required', 'numeric', 'min:0'],
            'transfer_price' => ['required', 'numeric', 'min:0'],
            'renew_price' => ['required', 'numeric', 'min:0'],
            'min_years' => ['required', 'integer', 'between:1,10'],
            'max_years' => ['required', 'integer', 'between:1,10', 'gte:min_years'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ], ['tld.unique' => __('This extension already has prices in this currency.')]);

        foreach (['register_price', 'transfer_price', 'renew_price'] as $key) {
            $data[$key] = Money::toMinor($data[$key]);
        }

        return $data + [
            'epp_required' => $request->boolean('epp_required'),
            'is_featured' => $request->boolean('is_featured'),
            'is_enabled' => $request->boolean('is_enabled'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }
}
