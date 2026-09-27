<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A developer's share of one paid invoice line for their item: a sale or a yearly update renewal.
 */
class DeveloperEarning extends Model
{
    protected $fillable = ['developer_id', 'marketplace_item_id', 'license_id', 'invoice_id', 'gross', 'developer_share', 'fee', 'share_percent', 'currency', 'payout_id'];

    protected function casts(): array
    {
        return [
            'gross' => 'integer',
            'developer_share' => 'integer',
            'fee' => 'integer',
            'share_percent' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Developer, $this>
     */
    public function developer(): BelongsTo
    {
        return $this->belongsTo(Developer::class);
    }

    /**
     * @return BelongsTo<MarketplaceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MarketplaceItem::class, 'marketplace_item_id');
    }
}
