<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One license check or download request from a site. Many different sites for one key means it
 * is being shared, which staff see on the license.
 */
class LicenseCheck extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['license_id', 'site', 'ip', 'version', 'matched'];

    protected function casts(): array
    {
        return ['matched' => 'boolean'];
    }

    /**
     * @return BelongsTo<License, $this>
     */
    public function license(): BelongsTo
    {
        return $this->belongsTo(License::class);
    }
}
