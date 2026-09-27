<?php

namespace App\Marketplace;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Talks to the marketplace store's API: the catalog, downloads and license checks.
 */
class MarketplaceClient
{
    private const CATALOG_CACHE = 'nuvabill.marketplace.catalog';

    private ?string $lastError = null;

    public function baseUrl(): string
    {
        return (string) config('nuvabill.marketplace.url');
    }

    /**
     * Every item in the marketplace, cached for half an hour. Empty when the store cannot be reached.
     *
     * @return list<array<string, mixed>>
     */
    public function catalog(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CATALOG_CACHE);
        }

        $cached = Cache::get(self::CATALOG_CACHE);

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $response = $this->http()->get($this->baseUrl().'/api/marketplace/v1/catalog', ['nuvabill' => config('nuvabill.version')]);
        } catch (Throwable $exception) {
            $this->lastError = __('The marketplace cannot be reached right now. Try again in a few minutes.');
            report($exception);

            return [];
        }

        if (! $response->successful() || ! is_array($response->json('items'))) {
            $this->lastError = __('The marketplace cannot be reached right now. Try again in a few minutes.');

            return [];
        }

        $items = array_values(array_filter($response->json('items'), fn ($item): bool => is_array($item)
            && is_string($item['slug'] ?? null)
            && PackageType::tryFrom((string) ($item['type'] ?? '')) !== null));

        Cache::put(self::CATALOG_CACHE, $items, 1800);

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function item(string $slug): ?array
    {
        foreach ($this->catalog() as $item) {
            if ($item['slug'] === $slug) {
                return $item;
            }
        }

        return null;
    }

    /**
     * The catalog if it is in the cache, without calling the store.
     *
     * @return list<array<string, mixed>>|null
     */
    public function cachedCatalog(): ?array
    {
        $cached = Cache::get(self::CATALOG_CACHE);

        return is_array($cached) ? $cached : null;
    }

    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * Ask the store for a download of the newest version. Paid items need their license key; the
     * store ties the key to this site the first time it is used.
     *
     * @return array{slug: string, type: string, version: string, url: string, sha256: string, signature: string}
     *
     * @throws RuntimeException With a message for staff when the store refuses.
     */
    public function download(string $slug, ?string $licenseKey): array
    {
        try {
            $response = $this->http()->post($this->baseUrl().'/api/marketplace/v1/download', [
                'slug' => $slug,
                'license_key' => $licenseKey ?: null,
                'site' => $this->site(),
                'nuvabill' => config('nuvabill.version'),
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException(__('The marketplace cannot be reached right now. Try again in a few minutes.'));
        }

        if (! $response->successful()) {
            throw new RuntimeException((string) ($response->json('message') ?: __('The marketplace refused the download (error :status).', ['status' => $response->status()])));
        }

        $data = (array) $response->json();

        foreach (['slug', 'type', 'version', 'url', 'sha256', 'signature'] as $field) {
            if (! is_string($data[$field] ?? null) || $data[$field] === '') {
                throw new RuntimeException(__('The marketplace sent an incomplete answer. Try again later.'));
            }
        }

        return $data;
    }

    /**
     * Check a license with the store.
     *
     * @return array{valid: bool, status: string, message: string}|null Null when the store cannot be reached.
     */
    public function checkLicense(string $slug, string $licenseKey, ?string $version = null): ?array
    {
        try {
            $response = $this->http()->post($this->baseUrl().'/api/marketplace/v1/licenses/check', [
                'slug' => $slug,
                'license_key' => $licenseKey,
                'site' => $this->site(),
                'version' => $version,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($response->serverError() || ! is_bool($response->json('valid'))) {
            return null;
        }

        return [
            'valid' => (bool) $response->json('valid'),
            'status' => (string) $response->json('status', ''),
            'message' => (string) $response->json('message', ''),
        ];
    }

    /**
     * This site's host name, which license keys are tied to.
     */
    public function site(): string
    {
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        if (($host === '' || $host === 'localhost') && app()->bound('request')) {
            $host = request()->getHost();
        }

        return strtolower($host);
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->timeout(20)->withUserAgent('Nuvabill/'.config('nuvabill.version'));
    }
}
