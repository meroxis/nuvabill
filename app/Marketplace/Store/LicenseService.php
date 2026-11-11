<?php

namespace App\Marketplace\Store;

use App\Mail\TemplateMailer;
use App\Marketplace\PackageType;
use App\Models\License;
use App\Models\MarketplaceDownload;
use App\Models\MarketplaceItem;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Support\Facades\DB;

/**
 * License keys for paid marketplace items: made when the purchase is paid and set up, checked by
 * sites once a day and on every download, tied to one site, and extended when updates are renewed.
 */
class LicenseService
{
    /**
     * Different sites a key may be checked from in 30 days before staff are warned it is shared.
     */
    public const SHARED_SITE_LIMIT = 3;

    /**
     * Different networks a key may be used from in 30 days before staff are warned it is shared,
     * whatever site names were sent.
     */
    public const SHARED_NETWORK_LIMIT = 5;

    /**
     * Different networks a key may download to test sites from in 30 days.
     */
    public const TEST_DOWNLOAD_NETWORKS = 5;

    public function __construct(private TemplateMailer $mailer) {}

    public function itemForService(Service $service): ?MarketplaceItem
    {
        $service->loadMissing('product');
        $slug = $service->product?->module_config['marketplace_item'] ?? null;

        return is_string($slug) ? MarketplaceItem::findBySlug($slug) : null;
    }

    /**
     * Make the key for a paid purchase, once. The service's domain is the site it is for.
     */
    public function issueFor(Service $service): ?License
    {
        $item = $this->itemForService($service);

        if ($item === null) {
            return null;
        }

        $existing = License::query()->where('service_id', $service->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $license = License::create([
            'key' => License::newKey(),
            'marketplace_item_id' => $item->id,
            'client_id' => $service->client_id,
            'service_id' => $service->id,
            'site' => filled($service->domain) ? License::normalizeSite((string) $service->domain) : null,
            'status' => License::STATUS_ACTIVE,
            'updates_until' => $service->billing_cycle->isRecurring() ? $service->next_due_date : null,
        ]);

        $item->increment('sales_count');
        Activity::log('license.issued', "License {$license->publicId()} for {$item->name} issued", $service, $service->client);

        $this->mailer->send('marketplace.license', $service->client, TemplateMailer::serviceContext($service) + [
            'item' => ['name' => $item->name],
            'license' => ['key' => $license->key, 'site' => (string) $license->site],
        ]);

        return $license;
    }

    /**
     * After a renewal is paid, updates are included until the service's next due date.
     */
    public function extendFor(Service $service): void
    {
        $license = License::query()->where('service_id', $service->id)->first();

        if ($license !== null && $service->next_due_date !== null && ($license->updates_until === null || $service->next_due_date->gt($license->updates_until))) {
            $license->update(['updates_until' => $service->next_due_date]);
        }
    }

    /**
     * Check a key for an item and a site. The first real site a key is used on becomes its site.
     * Test sites (localhost, *.test, and staging.… or dev.… of the key's own site) pass the check
     * and never take the key. Pass $download for a download: test sites can only download from a
     * few networks in 30 days.
     *
     * @return array{valid: bool, status: string, message: string, license: License|null}
     */
    public function verify(MarketplaceItem $item, ?string $key, string $site, ?string $ip = null, ?string $version = null, bool $record = true, bool $download = false): array
    {
        $site = License::normalizeSite($site);
        $license = filled($key) ? License::query()->where('key', strtoupper(trim((string) $key)))->first() : null;

        if (blank($key)) {
            return ['valid' => false, 'status' => 'unknown', 'message' => __(':item is a paid item. Enter the license key from your purchase.', ['item' => $item->name]), 'license' => null];
        }

        if ($license === null || $license->marketplace_item_id !== $item->id) {
            return ['valid' => false, 'status' => 'unknown', 'message' => __('This license key is not for :item.', ['item' => $item->name]), 'license' => null];
        }

        $test = $this->isTestSite($license, $site);
        $matched = $license->site === null || $license->site === $site || $test;
        // Looked up before this call is saved, so it does not count itself.
        $limited = $download && $test && $this->testDownloadsUsedUp($license, $ip);

        if ($record) {
            $license->checks()->create(['site' => $site, 'ip' => $ip, 'version' => $version, 'matched' => $matched]);
            $license->update(['last_seen_at' => now()]);
        }

        if (! $license->isActive()) {
            return ['valid' => false, 'status' => 'revoked', 'message' => __('This license was cancelled. Contact us if you think this is wrong.'), 'license' => $license];
        }

        if ($item->type === PackageType::License && $license->updates_until !== null && $license->updates_until->endOfDay()->isPast()) {
            return ['valid' => false, 'status' => 'expired', 'message' => __('This license ended on :date. Renew it from your account on :store.', ['date' => $license->updates_until->format('d M Y'), 'store' => parse_url((string) config('app.url'), PHP_URL_HOST)]), 'license' => $license];
        }

        if (! $matched) {
            return ['valid' => false, 'status' => 'wrong_site', 'message' => __('This key is used on :site. Move it from your account on :store first.', ['site' => $license->site, 'store' => parse_url((string) config('app.url'), PHP_URL_HOST)]), 'license' => $license];
        }

        if ($limited) {
            return ['valid' => false, 'status' => 'test_limit', 'message' => __('This key was used for test sites from too many places in the last 30 days. Use it on its own site, or try again later.'), 'license' => $license];
        }

        if ($license->site === null && ! $test) {
            $license->update(['site' => $site]);
        }

        return ['valid' => true, 'status' => 'active', 'message' => '', 'license' => $license];
    }

    /**
     * A site this key works on without being its own site: a local copy, or a test copy
     * (staging.… or dev.…) of the key's own site or of a parent of it, so staging.example.com is
     * a test copy of billing.example.com. Before a key has a site, any test copy name works and
     * leaves the key free, so a shop set up on its staging copy first keeps its key for the live
     * site (downloads to test sites stay limited). Any other name is a site of its own.
     */
    public function isTestSite(License $license, string $site): bool
    {
        $site = License::normalizeSite($site);
        $own = (string) $license->site;

        if ($site === $own) {
            return false;
        }

        if (License::isLocalSite($site)) {
            return true;
        }

        foreach (License::TEST_COPY_PREFIXES as $prefix) {
            if (str_starts_with($site, $prefix)) {
                $base = substr($site, strlen($prefix));

                if ($own === '') {
                    return str_contains($base, '.');
                }

                $domain = ConnectedSites::registrableDomain($own);

                return $base === $own || (str_ends_with($own, '.'.$base) && ($base === $domain || str_ends_with($base, '.'.$domain)));
            }
        }

        return false;
    }

    /**
     * Let the owner move a key to a new site, a few times a year. The year starts with the
     * first move and is counted on its own, so daily license checks do not keep it going.
     */
    public function moveToNewSite(License $license): bool
    {
        $moved = DB::transaction(function () use ($license): bool {
            $locked = License::query()->lockForUpdate()->findOrFail($license->id);

            if ($locked->site_changes_since === null || $locked->site_changes_since->lte(now()->subYear())) {
                $locked->site_changes = 0;
                $locked->site_changes_since = now();
            }

            if ($locked->site_changes >= License::SITE_CHANGES_PER_YEAR) {
                return false;
            }

            $locked->site = null;
            $locked->site_changes++;
            $locked->save();

            return true;
        });

        $license->refresh();

        if ($moved) {
            Activity::log('license.moved', "License {$license->publicId()} released from its site", $license->service, $license->client);
        }

        return $moved;
    }

    /**
     * Sites that used the key in the last 30 days, without its own test sites. More than a few
     * means the key is shared.
     *
     * @return list<string>
     */
    public function recentSites(License $license): array
    {
        return $license->checks()
            ->where('created_at', '>=', now()->subDays(30))
            ->distinct()
            ->pluck('site')
            ->reject(fn (string $site): bool => $this->isTestSite($license, $site))
            ->values()
            ->all();
    }

    /**
     * Networks the key was used from in the last 30 days, test sites included. A site name is
     * only what the caller says, so many networks also mean the key is shared.
     *
     * @return list<string>
     */
    public function recentNetworks(License $license): array
    {
        return $license->checks()
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull('ip')
            ->distinct()
            ->pluck('ip')
            ->map(fn (string $ip): ?string => SourceNetwork::of($ip))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function looksShared(License $license): bool
    {
        return count($this->recentSites($license)) > self::SHARED_SITE_LIMIT
            || count($this->recentNetworks($license)) > self::SHARED_NETWORK_LIMIT;
    }

    /**
     * Test sites download from a few networks in 30 days. A network that downloaded already
     * keeps working.
     */
    private function testDownloadsUsedUp(License $license, ?string $ip): bool
    {
        $networks = MarketplaceDownload::query()
            ->where('license_id', $license->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->get(['site', 'ip'])
            ->filter(fn (MarketplaceDownload $download): bool => $this->isTestSite($license, (string) $download->site))
            ->map(fn (MarketplaceDownload $download): string => (string) SourceNetwork::of($download->ip))
            ->unique();

        return ! $networks->contains((string) SourceNetwork::of($ip)) && $networks->count() >= self::TEST_DOWNLOAD_NETWORKS;
    }
}
