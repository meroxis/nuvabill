<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductGroup;
use App\Models\TldPrice;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(): View|RedirectResponse
    {
        // The marketplace store (my.nuvabill.com) sells themes and extensions, not hosting.
        if (config('nuvabill.marketplace.store')) {
            return redirect()->route('marketplace.index');
        }

        return view('theme::store.index', [
            'groups' => ProductGroup::query()
                ->visible()
                ->with(['products' => fn ($query) => $query->visible()->with('prices')])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->filter(fn (ProductGroup $group): bool => $group->products->isNotEmpty()),
            'currency' => $this->currency(),
        ]);
    }

    public function group(ProductGroup $group): View
    {
        abort_unless($group->is_visible, 404);

        return view('theme::store.group', [
            'group' => $group,
            'products' => $group->products()->visible()->with('prices')->get(),
            'currency' => $this->currency(),
        ]);
    }

    public function product(ProductGroup $group, Product $product): View
    {
        abort_unless($group->is_visible && $product->is_visible, 404);

        $product->load('prices');

        return view('theme::store.product', [
            'group' => $group,
            'product' => $product,
            'prices' => $product->pricesIn($this->currency()),
            'currency' => $this->currency(),
            'inStock' => $product->isInStock(),
            'addons' => ProductAddon::offeredFor($product, $this->currency()),
            'sellsDomains' => TldPrice::query()->enabled($this->currency())->exists(),
        ]);
    }

    private function currency(): string
    {
        return auth('web')->user()?->currency ?? (string) setting('billing.currency');
    }
}
