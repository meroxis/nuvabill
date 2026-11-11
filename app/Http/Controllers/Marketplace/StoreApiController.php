<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateMarketplaceSite;
use App\Marketplace\Store\DownloadBuilder;
use App\Marketplace\Store\LicenseService;
use App\Marketplace\Store\SourceNetwork;
use App\Marketplace\Store\StoreCatalog;
use App\Models\License;
use App\Models\MarketplaceDownload;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceSite;
use App\Models\MarketplaceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The API every copy of Nuvabill uses: the catalog, downloads and license checks.
 */
class StoreApiController extends Controller
{
    /**
     * New installs of one free item that are counted in a day from callers that are not
     * connected sites.
     */
    public const UNCONNECTED_INSTALLS_PER_DAY = 25;

    public function __construct(
        private StoreCatalog $catalog,
        private LicenseService $licenses,
    ) {}

    public function catalog(Request $request): JsonResponse
    {
        $version = (string) $request->query('nuvabill', '');

        return response()->json(['items' => $this->catalog->items($version !== '' ? $version : null)])
            ->header('Cache-Control', 'public, max-age=300');
    }

    public function download(Request $request, DownloadBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64'],
            'license_key' => ['nullable', 'string', 'max:64'],
            'site' => ['required', 'string', 'max:190'],
            'nuvabill' => ['nullable', 'string', 'max:20'],
        ]);

        $item = MarketplaceItem::findBySlug($data['slug']);

        if ($item !== null && ! $item->isLive()) {
            $item = null;
        }

        if ($item === null) {
            return response()->json(['message' => __('This item is not in the marketplace.')], 404);
        }

        $license = null;
        $site = $this->siteName($request, $data['site']);
        $version = $this->catalog->newestFor($item, $data['nuvabill'] ?? null);

        if (! $item->isFree()) {
            $check = $this->licenses->verify($item, $data['license_key'] ?? null, $site, $request->ip(), $version?->version, download: true);

            if (! $check['valid']) {
                return response()->json(['message' => $check['message'] ?: __('A valid license key is needed.')], 403);
            }

            $license = $check['license'];
            $version = $this->catalog->newestFor($item, $data['nuvabill'] ?? null, $license->updates_until);

            if ($version === null) {
                return response()->json(['message' => __('Your year of updates has ended. Renew updates in your account to download new versions.')], 403);
            }
        }

        if ($version === null) {
            return response()->json(['message' => __(':item needs a newer Nuvabill. Update Nuvabill first.', ['item' => $item->name])], 409);
        }

        $build = $builder->build($version, $license);
        $this->countDownload($request, $item, $version, $license, License::normalizeSite($site));

        return response()->json([
            // The slug the package was signed with. After a rename, older versions still carry the old one.
            'slug' => $version->packageSlug(),
            'type' => $item->type->value,
            'version' => $version->version,
            // Signed without the scheme and host, so it still works behind a proxy that talks plain HTTP to this server.
            'url' => url(URL::temporarySignedRoute('marketplace.api.file', now()->addMinutes(15), ['version' => $version->id, 'license' => $license?->id ?? 0], absolute: false)),
            'sha256' => $build['sha256'],
            'signature' => $build['signature'],
        ]);
    }

    /**
     * Note the download, and count a new install. A paid item counts each site that has a key.
     * A free item counts each connected site once: its address was checked when it connected.
     * Other callers can send any site name, so they count once per network, and only a few of
     * them a day. Test sites like localhost never count, and the same download again within an
     * hour is not saved twice.
     */
    private function countDownload(Request $request, MarketplaceItem $item, MarketplaceVersion $version, ?License $license, string $site): void
    {
        $ip = $request->ip();
        $network = SourceNetwork::of($ip);
        $caller = $this->connectedSite($request);
        // A site that still waits for store staff counts like any other caller.
        $connected = $caller?->isActive() ? $caller : null;
        $earlier = fn () => MarketplaceDownload::query()->where('marketplace_item_id', $item->id);

        $seen = match (true) {
            $license !== null => $earlier()->where('site', $site)->exists(),
            // Its own downloads, and those under its name from before downloads noted the connected site.
            $connected !== null => $earlier()
                ->where(fn ($query) => $query->where('marketplace_site_id', $connected->id)->orWhere(fn ($query) => $query->where('site', $site)->whereNull('network')))
                ->exists(),
            default => $network === null || $earlier()
                ->where(fn ($query) => $query->where('site', $site)->orWhere('network', $network)->orWhere('ip', $ip))
                ->exists(),
        };

        $repeat = $seen && $earlier()->where('site', $site)->where('ip', $ip)->where('created_at', '>=', now()->subHour())->exists();

        if (! $repeat) {
            MarketplaceDownload::create([
                'marketplace_item_id' => $item->id,
                'marketplace_version_id' => $version->id,
                'license_id' => $license?->id,
                'marketplace_site_id' => $caller?->id,
                'site' => $site,
                'ip' => $ip,
                'network' => $network,
            ]);
        }

        if ($seen || License::isTestSite($site)) {
            return;
        }

        if ($license === null && $connected === null && ! $this->underDailyLimit($item)) {
            return;
        }

        $item->increment('installs_count');
    }

    /**
     * Whether one more install from a caller that is not a connected site still counts today.
     */
    private function underDailyLimit(MarketplaceItem $item): bool
    {
        $counter = 'nuvabill.marketplace.installs.'.$item->id.'.'.now()->toDateString();
        Cache::add($counter, 0, now()->addDay());

        return Cache::increment($counter) <= self::UNCONNECTED_INSTALLS_PER_DAY;
    }

    /**
     * The site a call is for. A connected site proved its address when it connected, so that
     * address counts, whatever name it sends. Other callers are taken at their word.
     */
    private function siteName(Request $request, string $sent): string
    {
        return $this->connectedSite($request)?->host ?? $sent;
    }

    private function connectedSite(Request $request): ?MarketplaceSite
    {
        $site = $request->attributes->get(AuthenticateMarketplaceSite::ATTRIBUTE);

        return $site instanceof MarketplaceSite ? $site : null;
    }

    public function check(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'max:64'],
            'license_key' => ['required', 'string', 'max:64'],
            'site' => ['required', 'string', 'max:190'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);

        $item = MarketplaceItem::findBySlug($data['slug']);

        if ($item === null) {
            return response()->json(['valid' => false, 'status' => 'unknown', 'message' => __('This item is not in the marketplace.')]);
        }

        $result = $this->licenses->verify($item, $data['license_key'], $this->siteName($request, $data['site']), $request->ip(), $data['version'] ?? null);

        return response()->json([
            'valid' => $result['valid'],
            'status' => $result['status'],
            'message' => $result['message'],
            'updates_until' => $result['license']?->updates_until?->toDateString(),
        ]);
    }

    public function file(MarketplaceVersion $version, int $license, DownloadBuilder $builder): BinaryFileResponse
    {
        $version->loadMissing('item');
        abort_unless($version->status === MarketplaceVersion::STATUS_APPROVED, 404);

        $owner = $license > 0 ? License::query()->findOrFail($license) : null;
        abort_if($owner === null && ! $version->item->isFree(), 403);
        abort_if($owner !== null && $owner->marketplace_item_id !== $version->marketplace_item_id, 403);

        $build = $builder->build($version, $owner);

        return response()->download($build['path'], $version->packageSlug().'-'.$version->version.'.zip', ['Content-Type' => 'application/zip']);
    }
}
