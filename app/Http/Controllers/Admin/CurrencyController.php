<?php

namespace App\Http\Controllers\Admin;

use App\Billing\ExchangeRates;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Extensions\Gateways\Gateway;
use App\Http\Controllers\Controller;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Currencies: exchange rates for gateways that charge another currency than the
 * billing currency, for example dollar prices paid in dinar with Wayl, FIB or FastPay.
 */
class CurrencyController extends Controller
{
    public function edit(ExchangeRates $rates, ExtensionManager $extensions): View
    {
        $base = $rates->base();

        $needs = $extensions->ofType(ExtensionManifest::TYPE_GATEWAY)
            ->filter(fn (ExtensionManifest $manifest): bool => $extensions->isEnabled($manifest->slug))
            ->map(fn (ExtensionManifest $manifest) => rescue(fn () => $extensions->gateway($manifest->slug), report: false))
            ->filter(fn ($gateway): bool => $gateway !== null && ! $gateway->supportsCurrency($base))
            ->map(fn ($gateway): array => [
                'name' => $gateway->name(),
                'ready' => $gateway->chargeCurrencyFor($base) !== null,
                // A rate only helps a gateway that charges the converted amount.
                'converts' => ! $gateway instanceof Gateway || $gateway->convertsCurrency(),
            ]);

        return view('admin.settings.currencies', [
            'base' => $base,
            'rates' => $rates->all(),
            'currencies' => array_values(array_diff(SettingsController::CURRENCIES, [$base])),
            'needs' => $needs,
        ]);
    }

    public function update(Request $request, ExchangeRates $rates): RedirectResponse
    {
        $data = $request->validate([
            'rates' => ['array', 'max:40'],
            'rates.*.code' => ['nullable', Rule::in(SettingsController::CURRENCIES)],
            'rates.*.rate' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
        ]);

        $clean = collect($data['rates'] ?? [])
            ->filter(fn (array $row): bool => filled($row['code'] ?? null) && filled($row['rate'] ?? null))
            ->mapWithKeys(fn (array $row): array => [$row['code'] => (float) $row['rate']])
            ->all();

        $rates->save($clean);
        Activity::log('settings.currencies', 'Exchange rates changed: '.(collect($rates->all())->map(fn (float $rate, string $code): string => "1 {$rates->base()} = {$rate} {$code}")->implode(', ') ?: 'none'));

        return redirect()->route('admin.settings.currencies.edit')->with('status', __('Exchange rates saved.'));
    }
}
