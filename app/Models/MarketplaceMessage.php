<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message between reviewers and the developer about a version under review.
 */
class MarketplaceMessage extends Model
{
    public const FROM_DEVELOPER = 'developer';

    public const FROM_STAFF = 'staff';

    protected $fillable = ['marketplace_version_id', 'author_type', 'author_id', 'message'];

    /**
     * @return BelongsTo<MarketplaceVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(MarketplaceVersion::class, 'marketplace_version_id');
    }

    public function authorName(): string
    {
        return $this->author_type === self::FROM_STAFF ? __('Nuvabill review team') : (string) (Developer::query()->find($this->author_id)?->name ?? __('Developer'));
    }
}
