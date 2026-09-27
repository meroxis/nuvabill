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
        $newer = [];

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

            $notes = self::cleanNotes((string) ($item['body'] ?? ''));

            if (version_compare($version, (string) config('nuvabill.version'), '>')) {
                $newer[$version] = $notes;
            }

            if ($best !== null && version_compare($version, $best->version, '<=')) {
                continue;
            }

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

        if ($best === null || count($newer) < 2) {
            return $best;
        }

        // Several versions behind: show what changed in each of them, newest first.
        uksort($newer, fn (string $a, string $b): int => version_compare($b, $a));
        $notes = collect($newer)->map(fn (string $text, string $version): string => "### {$version}\n\n".($text !== '' ? $text : __('No release notes.')))->implode("\n\n");

        return new Release(
            version: $best->version,
            notes: $notes,
            zipUrl: $best->zipUrl,
            signatureUrl: $best->signatureUrl,
            publishedAt: $best->publishedAt,
            isSecurity: stripos($notes, '[security]') !== false,
            isPrerelease: $best->isPrerelease,
            size: $best->size,
        );
    }

    /**
     * GitHub adds a "Full Changelog" link and a "What's Changed" heading to generated notes; site owners only need the changes.
     */
    private static function cleanNotes(string $notes): string
    {
        $lines = preg_split('/\R/', $notes) ?: [];
        $kept = array_filter($lines, fn (string $line): bool => ! preg_match("/^\\s*(\\*\\*Full Changelog\\*\\*|#+\\s*What's Changed\\s*$)/i", $line));

        return trim(implode("\n", $kept));
    }
}
