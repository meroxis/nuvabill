<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Marketplace\Store\DownloadBuilder;
use App\Marketplace\Store\LicenseService;
use App\Marketplace\Store\StoreCatalog;
use App\Models\License;
use App\Models\MarketplaceDownload;
use App\Models\MarketplaceItem;
use App\Models\MarketplaceVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The API every copy of Nuvabill uses: the catalog, downloads and license checks.
 */
class StoreApiController extends Controller
{
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
        $version = $this->catalog->newestFor($item, $data['nuvabill'] ?? null);

        if (! $item->isFree()) {
            $check = $this->licenses->verify($item, $data['license_key'] ?? null, $data['site'], $request->ip(), $version?->version);

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
        $isNewSite = ! MarketplaceDownload::query()->where('marketplace_item_id', $item->id)->where('site', License::normalizeSite($data['site']))->exists();

        MarketplaceDownload::create([
            'marketplace_item_id' => $item->id,
            'marketplace_version_id' => $version->id,
            'license_id' => $license?->id,
            'site' => License::normalizeSite($data['site']),
            'ip' => $request->ip(),
        ]);

        if ($isNewSite) {
            $item->increment('installs_count');
        }

        return response()->json([
            'slug' => $item->slug,
            'type' => $item->type->value,
            'version' => $version->version,
            // Signed without the scheme and host, so it still works behind a proxy that talks plain HTTP to this server.
            'url' => url(URL::temporarySignedRoute('marketplace.api.file', now()->addMinutes(15), ['version' => $version->id, 'license' => $license?->id ?? 0], absolute: false)),
            'sha256' => $build['sha256'],
            'signature' => $build['signature'],
        ]);
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

        $result = $this->licenses->verify($item, $data['license_key'], $data['site'], $request->ip(), $data['version'] ?? null);

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

        return response()->download($build['path'], $version->item->slug.'-'.$version->version.'.zip', ['Content-Type' => 'application/zip']);
    }
}
