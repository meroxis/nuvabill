<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TaxRule;
use App\Support\Activity;
use App\Support\Countries;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Taxes: turn taxes on, choose whether prices include them, and the rules by country.
 */
class TaxController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.taxes', [
            'rules' => TaxRule::query()->orderByRaw('country is null')->orderBy('country')->orderBy('state')->get(),
            'countries' => Countries::all(),
        ]);
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['boolean'],
            'inclusive' => ['boolean'],
            'domains' => ['boolean'],
            'id_label' => ['required', 'string', 'max:40'],
            'company_tax_id' => ['nullable', 'string', 'max:64'],
        ]);

        $settings->setMany([
            'tax.enabled' => $request->boolean('enabled'),
            'tax.inclusive' => $request->boolean('inclusive'),
            'tax.domains' => $request->boolean('domains'),
            'tax.id_label' => $data['id_label'],
            'company.tax_id' => (string) ($data['company_tax_id'] ?? ''),
        ]);

        Activity::log('settings.taxes', 'Tax settings changed: '.($request->boolean('enabled') ? 'taxes on' : 'taxes off').($request->boolean('inclusive') ? ', prices include tax' : ''));

        return back()->with('status', __('Tax settings saved.'));
    }

    public function store(Request $request): RedirectResponse
    {
        $rule = TaxRule::create($this->validated($request));
        Activity::log('tax.created', "Tax rule {$rule->name} {$rule->percentLabel()} for {$rule->placeLabel()} added");

        return back()->with('status', __('Tax rule added.'));
    }

    public function update(Request $request, TaxRule $taxRule): RedirectResponse
    {
        $taxRule->update($this->validated($request));
        Activity::log('tax.updated', "Tax rule {$taxRule->name} {$taxRule->percentLabel()} for {$taxRule->placeLabel()} changed");

        return back()->with('status', __('Tax rule saved.'));
    }

    public function destroy(TaxRule $taxRule): RedirectResponse
    {
        $taxRule->delete();
        Activity::log('tax.deleted', "Tax rule {$taxRule->name} for {$taxRule->placeLabel()} removed");

        return back()->with('status', __('Tax rule removed. Invoices already sent keep their tax.'));
    }

    /**
     * @return array{name: string, rate: int, country: string|null, state: string|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'country' => ['nullable', Rule::in(array_keys(Countries::all()))],
            'state' => ['nullable', 'string', 'max:100'],
        ]);

        return [
            'name' => $data['name'],
            'rate' => (int) round((float) $data['rate'] * 100),
            'country' => $data['country'] ?? null,
            'state' => filled($data['country'] ?? null) && filled($data['state'] ?? null) ? trim($data['state']) : null,
        ];
    }
}
