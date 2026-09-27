<?php

namespace App\Http\Controllers\Marketplace\Admin;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\ItemPublisher;
use App\Models\MarketplaceVersion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function approve(Request $request, MarketplaceVersion $version, ItemPublisher $publisher): RedirectResponse
    {
        if ($version->hasFailures()) {
            return back()->with('error', __('This version failed an automatic check. Ask for changes instead.'));
        }

        $publisher->approve($version, $request->user('admin'), $request->input('message'));

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
