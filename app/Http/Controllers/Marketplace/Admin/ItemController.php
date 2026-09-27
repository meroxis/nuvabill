<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\ItemPublisher;
use App\Models\MarketplaceItem;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Staff manage listings: put items live or hide them, feature them, and set prices.
 */
class ItemController extends Controller
{
    public function index(Request $request): View
    {
        return view('admin.store.items', [
            'items' => MarketplaceItem::query()
                ->with('developer', 'latestVersion')
                ->when($request->string('q')->toString(), fn ($query, string $term) => $query->where('name', 'like', "%{$term}%")->orWhere('slug', 'like', "%{$term}%"))
                ->orderByDesc('is_featured')
                ->orderBy('name')
                ->paginate(30)
                ->withQueryString(),
        ]);
    }

    public function edit(MarketplaceItem $item): View
    {
        return view('admin.store.item-form', ['item' => $item->load('developer', 'latestVersion'), 'categories' => MarketplaceItem::CATEGORIES]);
    }

    public function update(Request $request, MarketplaceItem $item, ItemPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([MarketplaceItem::STATUS_DRAFT, MarketplaceItem::STATUS_LIVE, MarketplaceItem::STATUS_HIDDEN])],
            'is_featured' => ['boolean'],
            'category' => ['nullable', Rule::in(MarketplaceItem::CATEGORIES)],
            'price' => ['required', 'numeric', 'min:0', 'max:10000'],
            'update_price' => ['nullable', 'numeric', 'min:0', 'lte:price'],
            'demo_url' => ['nullable', 'url', 'max:255'],
        ]);

        $item->update([
            'status' => $data['status'],
            'is_featured' => $request->boolean('is_featured'),
            'category' => $data['category'] ?? null,
            'price' => Money::toMinor($data['price']),
            'update_price' => Money::toMinor($data['update_price'] ?? 0),
            'demo_url' => $data['demo_url'] ?? null,
        ]);

        $publisher->syncProduct($item->refresh());
        Activity::log('marketplace.item', "Marketplace item {$item->name} saved");

        return redirect()->route('admin.store.items.index')->with('status', __(':name saved.', ['name' => $item->name]));
    }
}
