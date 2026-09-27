<?php

namespace App\Marketplace\Store;

use App\Marketplace\PackageSignature;
use App\Models\License;
use App\Models\MarketplaceVersion;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Makes the file a site downloads. Free items get the approved package as it is. Paid items get
 * a copy stamped with the buyer's license (a signed ".nuvabill-license" file), signed again, so a
 * copy found on a "nulled" site shows exactly which license it came from.
 */
class DownloadBuilder
{
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

        $path = storage_path('app/private/marketplace/builds/'.$license->id.'/'.$version->item->slug.'-'.$version->version.'.zip');

        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path));

            if (! copy($version->path(), $path)) {
                throw new RuntimeException('The package file is missing on the store.');
            }

            $zip = new ZipArchive;
            $zip->open($path);
            $zip->addFromString($this->prefix($zip).'.nuvabill-license', $this->stamp($license, $version));
            $zip->close();
        }

        $sha256 = (string) hash_file('sha256', $path);

        return [
            'path' => $path,
            'sha256' => $sha256,
            'signature' => PackageSignature::sign($version->item->slug, $version->version, $sha256, $this->key->secret()),
        ];
    }

    /**
     * The license stamp, signed by the store so it cannot be forged or moved to another copy.
     */
    public function stamp(License $license, MarketplaceVersion $version): string
    {
        $data = [
            'license' => $license->publicId(),
            'item' => $version->item->slug,
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
