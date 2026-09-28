<?php

namespace App\Models;

use App\Health\CoreFiles;
use App\Marketplace\PackageType;
use Illuminate\Database\Eloquent\Model;

/**
 * A theme, order form or extension this copy of Nuvabill installed from the marketplace,
 * with the license key it was bought with and the result of the last license check.
 */
class MarketplaceInstall extends Model
{
    public const LICENSE_VALID = 'valid';

    public const LICENSE_INVALID = 'invalid';

    public const LICENSE_UNKNOWN = 'unknown';

    protected $fillable = ['slug', 'type', 'name', 'version', 'license_key', 'license_status', 'license_message', 'license_checked_at', 'file_hashes'];

    protected $hidden = ['license_key'];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'license_key' => 'encrypted',
            'license_checked_at' => 'datetime',
            'file_hashes' => 'array',
        ];
    }

    /**
     * Fingerprints of the package's code files as they were installed: path => SHA-256.
     *
     * @return array<string, string>
     */
    public static function fingerprint(string $directory): array
    {
        $hashes = [];

        if (! is_dir($directory)) {
            return $hashes;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && CoreFiles::isCode($file->getFilename())) {
                $hashes[ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($directory))), '/')] = (string) hash_file('sha256', $file->getPathname());
            }
        }

        ksort($hashes);

        return $hashes;
    }

    public function hasInvalidLicense(): bool
    {
        return $this->license_status === self::LICENSE_INVALID;
    }
}
