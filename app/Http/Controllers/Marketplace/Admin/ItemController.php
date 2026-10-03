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
 * Staff manage listings: put items live or hide them, feature them, set prices, and check the
 * listing changes developers make after their item was approved.
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

    /**
     * Approve or turn down the listing changes a developer made to an approved item. Only the
     * changes staff saw are approved: when the developer changed them again, staff look again.
     */
    public function listing(Request $request, MarketplaceItem $item, ItemPublisher $publisher): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'discard'])],
            'seen' => ['required', 'string', 'max:64'],
        ]);

        if ($item->pending_listing === null) {
            return back()->with('error', __('No listing changes are waiting.'));
        }

        if (! hash_equals(self::fingerprint($item), $data['seen'])) {
            return back()->with('error', __('The developer changed the listing again. Check the changes once more.'));
        }

        if ($data['decision'] === 'approve') {
            $publisher->approveListing($item, $request->user('admin'));

            return back()->with('status', __('The listing changes are live.'));
        }

        $publisher->discardListing($item, $request->user('admin'));

        return back()->with('status', __('The listing changes were turned down.'));
    }

    /**
     * A short fingerprint of the waiting listing changes, sent with the decision form.
     */
    public static function fingerprint(MarketplaceItem $item): string
    {
        return hash('sha256', (string) json_encode($item->pending_listing));
    }
}
