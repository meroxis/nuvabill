<?php

namespace App\Http\Controllers\Admin;

use App\Enums\BillingCycle;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Product add-ons: extras such as daily backups that clients tick when they order a service.
 */
class ProductAddonController extends Controller
{
    public function index(): View
    {
        return view('admin.products.addons', [
            'addons' => ProductAddon::query()->with('prices')->orderBy('sort_order')->orderBy('name')->get(),
            'products' => Product::query()->pluck('name', 'id'),
            'currency' => setting('billing.currency'),
        ]);
    }

    public function create(): View
    {
        return $this->form(new ProductAddon(['is_visible' => true]));
    }

    public function store(Request $request): RedirectResponse
    {
        $addon = DB::transaction(function () use ($request): ProductAddon {
            $addon = ProductAddon::create($this->attributes($request));
            $this->syncPrices($addon, (array) $request->input('prices', []));

            return $addon;
        });

        Activity::log('addon.created', "Product add-on {$addon->name} created");

        return redirect()->route('admin.product-addons.index')->with('status', __('Add-on created.'));
    }

    public function edit(ProductAddon $productAddon): View
    {
        return $this->form($productAddon->load('prices'));
    }

    public function update(Request $request, ProductAddon $productAddon): RedirectResponse
    {
        DB::transaction(function () use ($request, $productAddon): void {
            $productAddon->update($this->attributes($request));
            $this->syncPrices($productAddon, (array) $request->input('prices', []));
        });

        Activity::log('addon.updated', "Product add-on {$productAddon->name} updated");

        return redirect()->route('admin.product-addons.index')->with('status', __('Add-on saved. Clients who have it keep their current price.'));
    }

    public function destroy(ProductAddon $productAddon): RedirectResponse
    {
        $productAddon->delete();
        Activity::log('addon.deleted', "Product add-on {$productAddon->name} deleted");

        return redirect()->route('admin.product-addons.index')->with('status', __('Add-on deleted. Clients who have it keep it on their services.'));
    }

    private function form(ProductAddon $addon): View
    {
        return view('admin.products.addon-form', [
            'addon' => $addon,
            'products' => Product::query()->orderBy('name')->pluck('name', 'id')->all(),
            'cycles' => collect(BillingCycle::cases())->reject(fn (BillingCycle $cycle): bool => $cycle === BillingCycle::Free)->values(),
            'currency' => setting('billing.currency'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => ['integer', 'exists:products,id'],
            'is_visible' => ['boolean'],
            'is_popular' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'between:0,9999'],
            'prices' => ['array'],
            'prices.*.enabled' => ['boolean'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'prices.*.setup_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'icon' => ['nullable', Rule::in(['shield', 'server', 'globe', 'lock', 'mail', 'zap', 'star', 'download', 'refresh'])],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'product_ids' => array_values(array_map('intval', $data['product_ids'] ?? [])) ?: null,
            'is_visible' => $request->boolean('is_visible'),
            'is_popular' => $request->boolean('is_popular'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ];
    }

    /**
     * @param  array<string, array{enabled?: bool|string, price?: string|null, setup_fee?: string|null}>  $prices
     */
    private function syncPrices(ProductAddon $addon, array $prices): void
    {
        $currency = (string) setting('billing.currency');

        foreach (BillingCycle::cases() as $cycle) {
            $input = $prices[$cycle->value] ?? [];

            if ($cycle === BillingCycle::Free || empty($input['enabled'])) {
                $addon->prices()->where('currency', $currency)->where('billing_cycle', $cycle)->delete();

                continue;
            }

            $addon->prices()->updateOrCreate(
                ['currency' => $currency, 'billing_cycle' => $cycle],
                ['price' => Money::toMinor($input['price'] ?? 0), 'setup_fee' => Money::toMinor($input['setup_fee'] ?? 0)],
            );
        }
    }
}
