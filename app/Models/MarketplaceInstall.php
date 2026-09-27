<?php

namespace App\Models;

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

    protected $fillable = ['slug', 'type', 'name', 'version', 'license_key', 'license_status', 'license_message', 'license_checked_at'];

    protected $hidden = ['license_key'];

    protected function casts(): array
    {
        return [
            'type' => PackageType::class,
            'license_key' => 'encrypted',
            'license_checked_at' => 'datetime',
        ];
    }

    public function hasInvalidLicense(): bool
    {
        return $this->license_status === self::LICENSE_INVALID;
    }
}
