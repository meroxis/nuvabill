<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Marketplace\PackageType;
use App\Models\MarketplaceItem;
use App\Support\WhiteLabel;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public marketplace on the store: browse, read about an item, and buy a license.
 */
class MarketplacePageController extends Controller
{
    public function index(Request $request): View
    {
        $type = PackageType::tryFrom((string) $request->query('type'));
        $category = (string) $request->query('category', '');
        $price = (string) $request->query('price', '');
        $search = trim((string) $request->query('q', ''));

        $items = MarketplaceItem::query()
            ->live()
            ->with('developer', 'latestVersion')
            ->when($type, fn ($query) => $query->where('type', $type))
            ->when($category !== '', fn ($query) => $query->where('category', $category))
            ->when($price === 'free', fn ($query) => $query->where('price', 0))
            ->when($price === 'paid', fn ($query) => $query->where('price', '>', 0))
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$search}%")->orWhere('summary', 'like', "%{$search}%")))
            ->orderByDesc('is_featured')
            ->orderByDesc('installs_count')
            ->orderBy('name')
            ->get();

        $filtered = $type !== null || $category !== '' || $price !== '' || $search !== '';

        return view('theme::marketplace.index', [
            'items' => $filtered ? $items : $items->reject(fn (MarketplaceItem $item): bool => $item->is_featured)->values(),
            'featured' => $filtered ? collect() : $items->filter(fn (MarketplaceItem $item): bool => $item->is_featured)->take(2)->values(),
            'categories' => MarketplaceItem::query()->live()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category'),
            'type' => $type,
            'category' => $category,
            'price' => $price,
            'search' => $search,
            'share' => (int) setting('marketplace.developer_share', 83),
        ]);
    }

    public function show(MarketplaceItem $item): View
    {
        abort_unless($item->isLive(), 404);

        $item->load('developer', 'latestVersion', 'product.prices');

        return view('theme::marketplace.show', [
            'item' => $item,
            'price' => $item->product?->prices->first(),
            'share' => (int) setting('marketplace.developer_share', 83),
        ]);
    }

    /**
     * The White-label license that removes the "Powered by Nuvabill" credit, sold as a yearly license.
     */
    public function whiteLabel(): View
    {
        $item = MarketplaceItem::query()->where('slug', WhiteLabel::SLUG)->where('status', MarketplaceItem::STATUS_LIVE)->with('product.prices')->first();
        abort_if($item === null || $item->product === null, 404);

        return view('theme::marketplace.white-label', ['item' => $item, 'price' => $item->product->prices->first()]);
    }

    public function developers(): View
    {
        return view('theme::marketplace.developers', [
            'share' => (int) setting('marketplace.developer_share', 83),
            'developer' => auth('web')->user()?->developer,
        ]);
    }
}
