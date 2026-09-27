<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One package download from the store, for install counts.
 */
class MarketplaceDownload extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['marketplace_item_id', 'marketplace_version_id', 'license_id', 'site', 'ip'];
}
