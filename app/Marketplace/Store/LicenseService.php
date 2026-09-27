<?php

namespace App\Marketplace\Store;

use App\Mail\TemplateMailer;
use App\Models\License;
use App\Models\MarketplaceItem;
use App\Models\Service;
use App\Support\Activity;

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

    public function __construct(private TemplateMailer $mailer) {}

    public function itemForService(Service $service): ?MarketplaceItem
    {
        $service->loadMissing('product');
        $slug = $service->product?->module_config['marketplace_item'] ?? null;

        return is_string($slug) ? MarketplaceItem::query()->where('slug', $slug)->first() : null;
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
     * Check a key for an item and a site. The first real site a key is used on becomes its site;
     * test sites (localhost, *.test, staging.*) always work and never take the key.
     *
     * @return array{valid: bool, status: string, message: string, license: License|null}
     */
    public function verify(MarketplaceItem $item, ?string $key, string $site, ?string $ip = null, ?string $version = null, bool $record = true): array
    {
        $site = License::normalizeSite($site);
        $license = filled($key) ? License::query()->where('key', strtoupper(trim((string) $key)))->first() : null;

        if ($license === null || $license->marketplace_item_id !== $item->id) {
            return ['valid' => false, 'status' => 'unknown', 'message' => __('This license key is not for :item.', ['item' => $item->name]), 'license' => null];
        }

        $matched = $license->site === null || $license->site === $site || License::isTestSite($site);

        if ($record) {
            $license->checks()->create(['site' => $site, 'ip' => $ip, 'version' => $version, 'matched' => $matched]);
            $license->update(['last_seen_at' => now()]);
        }

        if (! $license->isActive()) {
            return ['valid' => false, 'status' => 'revoked', 'message' => __('This license was cancelled. Contact us if you think this is wrong.'), 'license' => $license];
        }

        if (! $matched) {
            return ['valid' => false, 'status' => 'wrong_site', 'message' => __('This key is used on :site. Move it from your account on :store first.', ['site' => $license->site, 'store' => parse_url((string) config('app.url'), PHP_URL_HOST)]), 'license' => $license];
        }

        if ($license->site === null && ! License::isTestSite($site)) {
            $license->update(['site' => $site]);
        }

        return ['valid' => true, 'status' => 'active', 'message' => '', 'license' => $license];
    }

    /**
     * Let the owner move a key to a new site, a few times a year.
     */
    public function moveToNewSite(License $license): bool
    {
        if ($license->site_changes >= License::SITE_CHANGES_PER_YEAR && $license->updated_at?->gt(now()->subYear())) {
            return false;
        }

        $license->update(['site' => null, 'site_changes' => $license->site_changes + 1]);
        Activity::log('license.moved', "License {$license->publicId()} released from its site", $license->service, $license->client);

        return true;
    }

    /**
     * Sites that used the key in the last 30 days. More than a few means the key is shared.
     *
     * @return list<string>
     */
    public function recentSites(License $license): array
    {
        return $license->checks()
            ->where('created_at', '>=', now()->subDays(30))
            ->pluck('site')
            ->unique()
            ->reject(fn (string $site): bool => License::isTestSite($site))
            ->values()
            ->all();
    }

    public function looksShared(License $license): bool
    {
        return count($this->recentSites($license)) > self::SHARED_SITE_LIMIT;
    }
}
