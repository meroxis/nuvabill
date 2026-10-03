<?php

namespace App\Marketplace\Store;

use App\Marketplace\PackageSignature;
use App\Models\License;
use App\Models\MarketplaceVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Makes the file a site downloads. Free items get the approved package as it is. Paid items get
 * a copy stamped with the buyer's license (a signed ".nuvabill-license" file), signed again, so a
 * copy found on a "nulled" site shows exactly which license it came from.
 */
class DownloadBuilder
{
    private const STAMP = '.nuvabill-license';

    public function __construct(private SigningKey $key) {}

    /**
     * @return array{path: string, sha256: string, signature: string}
     */
    public function build(MarketplaceVersion $version, ?License $license): array
    {
        $version->loadMissing('item');

        if ($license === null) {
            return ['path' => $version->path(), 'sha256' => $version->sha256, 'signature' => (string) $version->signature];
        }

        // The slug inside the package, which stays the old one in versions made before a rename.
        $slug = $version->packageSlug();
        $path = storage_path('app/private/marketplace/builds/'.$license->id.'/'.$slug.'-'.$version->version.'.zip');

        // One build at a time per license and version, so two downloads never write the same file.
        Cache::lock('marketplace-build:'.$license->id.':'.$version->id, 60)->block(30, function () use ($version, $license, $path): void {
            if (! $this->isStamped($path)) {
                $this->stampCopy($version, $license, $path);
            }
        });

        $sha256 = (string) hash_file('sha256', $path);

        return [
            'path' => $path,
            'sha256' => $sha256,
            'signature' => PackageSignature::sign($slug, $version->version, $sha256, $this->key->secret()),
        ];
    }

    /**
     * The license stamp, signed by the store so it cannot be forged or moved to another copy.
     */
    public function stamp(License $license, MarketplaceVersion $version): string
    {
        $data = [
            'license' => $license->publicId(),
            'item' => $version->packageSlug(),
            'version' => $version->version,
            'site' => $license->site,
            'key_ends' => substr($license->key, -4),
            'issued_at' => now()->toIso8601String(),
        ];

        $json = (string) json_encode($data, JSON_UNESCAPED_SLASHES);
        $secret = base64_decode($this->key->secret(), true);

        return (string) json_encode($data + ['signature' => base64_encode(sodium_crypto_sign_detached($json, (string) $secret))], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A finished build: a zip that opens and has the stamp. A copy cut off half way (a full
     * disk) or left without the stamp is built again.
     */
    private function isStamped(string $path): bool
    {
        if (! is_file($path)) {
            return false;
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return false;
        }

        $stamped = $zip->locateName($this->prefix($zip).self::STAMP) !== false;
        $zip->close();

        return $stamped;
    }

    /**
     * Build the stamped copy in a temporary file next to it, then move it into place, so a
     * build that fails half way never leaves a broken file behind.
     */
    private function stampCopy(MarketplaceVersion $version, License $license, string $path): void
    {
        File::ensureDirectoryExists(dirname($path));
        $temporary = $path.'.tmp-'.Str::random(8);

        try {
            if (! is_file($version->path()) || ! @copy($version->path(), $temporary)) {
                throw new RuntimeException('The package file is missing on the store.');
            }

            $zip = new ZipArchive;

            if ($zip->open($temporary) !== true) {
                throw new RuntimeException('The package file on the store cannot be opened.');
            }

            if (! $zip->addFromString($this->prefix($zip).self::STAMP, $this->stamp($license, $version)) || ! $zip->close()) {
                throw new RuntimeException('The license could not be added to the package.');
            }

            if (! @rename($temporary, $path)) {
                throw new RuntimeException('The package could not be saved on the store.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    /**
     * Put the stamp next to the manifest, also when the zip has one top folder.
     */
    private function prefix(ZipArchive $zip): string
    {
        foreach (['theme.json', 'orderform.json', 'extension.json'] as $manifest) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if ($name === $manifest || str_ends_with($name, '/'.$manifest) && substr_count($name, '/') === 1) {
                    return substr($name, 0, -strlen($manifest));
                }
            }
        }

        return '';
    }
}
