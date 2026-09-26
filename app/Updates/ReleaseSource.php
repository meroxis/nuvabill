<?php

namespace App\Updates;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads releases from the public GitHub repository.
 */
class ReleaseSource
{
    /**
     * The newest release for the channel: "stable" skips pre-releases, "beta" includes them.
     */
    public function latest(string $channel): ?Release
    {
        $repository = (string) config('nuvabill.updates.repository');
        $response = Http::acceptJson()
            ->withHeaders(['User-Agent' => 'Nuvabill-Updater/'.config('nuvabill.version')])
            ->timeout(20)
            ->get(rtrim((string) config('nuvabill.updates.api_url'), '/')."/repos/{$repository}/releases", ['per_page' => 30]);

        if ($response->failed()) {
            throw new RuntimeException("Could not reach the update server (HTTP {$response->status()}).");
        }

        $best = null;

        foreach ((array) $response->json() as $item) {
            if (! is_array($item) || ($item['draft'] ?? false)) {
                continue;
            }

            $isPrerelease = (bool) ($item['prerelease'] ?? false);

            if ($isPrerelease && $channel === 'stable') {
                continue;
            }

            $version = ltrim((string) ($item['tag_name'] ?? ''), 'vV');

            if (! preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/', $version)) {
                continue;
            }

            $assets = collect($item['assets'] ?? [])->keyBy('name');
            $zip = $assets->get("nuvabill-{$version}.zip");
            $signature = $assets->get("nuvabill-{$version}.zip.sig");

            if ($zip === null || $signature === null) {
                continue;
            }

            if ($best !== null && version_compare($version, $best->version, '<=')) {
                continue;
            }

            $notes = (string) ($item['body'] ?? '');

            $best = new Release(
                version: $version,
                notes: $notes,
                zipUrl: (string) $zip['browser_download_url'],
                signatureUrl: (string) $signature['browser_download_url'],
                publishedAt: (string) ($item['published_at'] ?? ''),
                isSecurity: stripos($notes, '[security]') !== false,
                isPrerelease: $isPrerelease,
                size: (int) ($zip['size'] ?? 0),
            );
        }

        return $best;
    }
}
