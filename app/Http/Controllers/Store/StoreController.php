<?php

namespace App\Http\Controllers\Store;

use App\Billing\ResourceOptions;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductGroup;
use App\Models\TldPrice;
use App\Provisioning\OrderFields;
use App\Seo\StorePages;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StoreController extends Controller
{
    public function index(StorePages $seo): View|RedirectResponse
    {
        // The marketplace store (my.nuvabill.com) sells themes and extensions, not hosting.
        if (config('nuvabill.marketplace.store')) {
            return redirect()->route('marketplace.index');
        }

        $groups = ProductGroup::query()
            ->visible()
            ->with(['products' => fn ($query) => $query->visible()->with('prices')])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->filter(fn (ProductGroup $group): bool => $group->products->isNotEmpty());

        // With a website from an add-on at "/", this page lives at /store and the website is the home page.
        if (rescue(fn (): bool => app(ExtensionManager::class)->homePageAddon() !== null, false, report: false)) {
            $seo->storeOverview();
        } else {
            $seo->home();
        }

        return view('theme::store.index', [
            'groups' => $groups,
            'currency' => $this->currency(),
        ]);
    }

    public function group(ProductGroup $group, StorePages $seo): View
    {
        abort_unless($group->is_visible, 404);
        $seo->group($group);

        return view('theme::store.group', [
            'group' => $group,
            'products' => $group->products()->visible()->with('prices')->get(),
            'currency' => $this->currency(),
        ]);
    }

    public function product(ProductGroup $group, Product $product, StorePages $seo): View
    {
        abort_unless($group->is_visible && $product->is_visible, 404);

        $product->load('prices');
        $seo->product($group, $product, $product->pricesIn($this->currency()), $product->isInStock(), $this->currency());

        return view('theme::store.product', [
            'group' => $group,
            'product' => $product,
            'prices' => $product->pricesIn($this->currency()),
            'currency' => $this->currency(),
            'inStock' => $product->isInStock(),
            'addons' => ProductAddon::offeredFor($product, $this->currency()),
            // Read from the database only: a store page never asks a server module.
            'resources' => app(ResourceOptions::class)->forStore($product, $this->currency()),
            'sellsDomains' => TldPrice::query()->enabled($this->currency())->exists(),
            // Fields the server module asks for (HasOrderFields): declared by the module, no server call.
            'orderFields' => app(OrderFields::class)->forStore($product),
        ]);
    }

    private function currency(): string
    {
        return auth('web')->user()?->currency ?? (string) setting('billing.currency');
    }
}
