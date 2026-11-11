<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\ItemPublisher;
use App\Marketplace\Store\PackageInspector;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Staff review queue: automatic check results, the reviewer's checklist, messages, and the
 * approve / ask for changes / reject decision.
 */
class ReviewController extends Controller
{
    public function index(Request $request): View|RedirectResponse
    {
        $first = $this->queue((string) $request->query('status', 'waiting'))->first();

        return $first !== null
            ? redirect()->route('admin.store.reviews.show', [$first, 'status' => $request->query('status')])
            : $this->page($request, null);
    }

    public function show(Request $request, MarketplaceVersion $version): View
    {
        return $this->page($request, $version);
    }

    public function download(MarketplaceVersion $version): BinaryFileResponse
    {
        $version->loadMissing('item');

        return response()->download($version->path(), $version->item->slug.'-'.$version->version.'.zip');
    }

    public function checklist(Request $request, MarketplaceVersion $version): RedirectResponse
    {
        $data = $request->validate(['checklist' => ['array'], 'checklist.*' => ['in:'.implode(',', array_keys(MarketplaceVersion::CHECKLIST))]]);
        $version->update(['checklist' => array_values($data['checklist'] ?? [])]);

        return back()->with('status', __('Checklist saved.'));
    }

    public function approve(Request $request, MarketplaceVersion $version, ItemPublisher $publisher, PackageInspector $inspector): RedirectResponse
    {
        if ($version->hasFailures()) {
            return back()->with('error', __('This version failed an automatic check. Ask for changes instead.'));
        }

        if (! in_array($version->status, [MarketplaceVersion::STATUS_PENDING, MarketplaceVersion::STATUS_CHANGES], true)) {
            return back()->with('error', __('This version was already reviewed.'));
        }

        // An older version approved after a newer one would sit next to it and confuse buyers.
        $latest = $version->item->latestVersion;

        if ($latest !== null && version_compare($version->version, $latest->version, '<=')) {
            return back()->with('error', __('A newer version is already live. Reject this one.'));
        }

        if ($this->findsSomethingNew($version, $inspector)) {
            return back()->with('error', __('The automatic checks were run again and found something new. Look at the checks, then decide again.'));
        }

        // The first approval also puts the listing live. The item is held from the check to the
        // approval, so the listing that goes live is the one this reviewer saw.
        $approved = DB::transaction(function () use ($request, $version, $publisher): bool {
            $item = MarketplaceItem::query()->lockForUpdate()->findOrFail($version->marketplace_item_id);

            if ($item->latest_version_id === null && ! hash_equals($item->listingFingerprint(), (string) $request->input('seen'))) {
                return false;
            }

            $publisher->approve($version->setRelation('item', $item), $request->user('admin'), $request->input('message'));

            return true;
        });

        if (! $approved) {
            return back()->with('error', __('The developer changed the listing after you opened this page. Look at the listing again, then decide.'));
        }

        return redirect()->route('admin.store.reviews.index')->with('status', __(':name :version is approved, signed and live.', ['name' => $version->item->name, 'version' => $version->version]));
    }

    public function changes(Request $request, MarketplaceVersion $version, ItemPublisher $publisher): RedirectResponse
    {
        $message = (string) $request->validate(['message' => ['required', 'string', 'max:3000']])['message'];
        $publisher->requestChanges($version, $request->user('admin'), $message);

        return redirect()->route('admin.store.reviews.index')->with('status', __('Sent back to the developer.'));
    }

    public function reject(Request $request, MarketplaceVersion $version, ItemPublisher $publisher): RedirectResponse
    {
        $message = (string) $request->validate(['message' => ['required', 'string', 'max:3000']])['message'];
        $publisher->reject($version, $request->user('admin'), $message);

        return redirect()->route('admin.store.reviews.index')->with('status', __('Rejected. The developer was told why.'));
    }

    /**
     * The checks get stricter with updates. Right before a package is signed they run again
     * with today's rules, so a version that waited in the queue is never signed on older
     * results. When they find something the reviewer has not seen, it is saved and shown first.
     */
    private function findsSomethingNew(MarketplaceVersion $version, PackageInspector $inspector): bool
    {
        $item = $version->item;
        $report = $inspector->inspect($version->path(), $item, $item->latestVersion?->version);

        // A version sent before the item got a new slug still carries the old one, which is fine.
        $renamed = $report['type'] === $item->type->value && MarketplaceItem::renamedTo($report['slug']) === $item->slug;
        $checks = array_values(array_filter($report['checks'], fn (array $check): bool => ! ($renamed && $check['key'] === 'match')));

        // The list of risky functions reads the same in every language, so it is compared word for word.
        $findings = fn (array $checks): array => collect($checks)
            ->where('level', '!=', 'ok')
            ->map(fn (array $check): string => $check['key'].':'.$check['level'].($check['key'] === 'functions' ? ':'.$check['text'] : ''))
            ->all();

        if (array_diff($findings($checks), $findings((array) $version->checks)) === []) {
            return false;
        }

        $version->update(['checks' => $checks]);

        return true;
    }

    private function page(Request $request, ?MarketplaceVersion $version): View
    {
        $status = (string) $request->query('status', 'waiting');
        $version?->load('item.developer.items', 'messages');

        return view('admin.store.reviews', [
            'status' => $status,
            'queue' => $this->queue($status)->get(),
            'counts' => [
                'waiting' => MarketplaceVersion::query()->where('status', MarketplaceVersion::STATUS_PENDING)->count(),
                'developer' => MarketplaceVersion::query()->where('status', MarketplaceVersion::STATUS_CHANGES)->count(),
            ],
            'version' => $version,
            // Developers' listing changes on approved items, checked on the item's page.
            'listings' => MarketplaceItem::query()->whereNotNull('pending_listing')->with('developer')->oldest('updated_at')->get(),
            'approvedThisWeek' => MarketplaceVersion::query()->where('status', MarketplaceVersion::STATUS_APPROVED)->where('reviewed_at', '>=', now()->subWeek())->count(),
        ]);
    }

    /**
     * @return Builder<MarketplaceVersion>
     */
    private function queue(string $status)
    {
        return MarketplaceVersion::query()
            ->with('item.developer')
            ->where('status', match ($status) {
                'developer' => MarketplaceVersion::STATUS_CHANGES,
                'done' => MarketplaceVersion::STATUS_APPROVED,
                default => MarketplaceVersion::STATUS_PENDING,
            })
            ->when($status === 'done', fn ($query) => $query->latest('reviewed_at')->limit(30), fn ($query) => $query->oldest('id'));
    }
}
