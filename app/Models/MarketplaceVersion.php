<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One uploaded version of a marketplace item. It goes live once staff approve it after the
 * automatic checks and their own test; approving signs it with the store's key.
 */
class MarketplaceVersion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_CHANGES = 'changes';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * What a reviewer tries before approving.
     *
     * @var array<string, string>
     */
    public const CHECKLIST = [
        'installs' => 'Installs without errors',
        'settings' => 'Settings page works',
        'works' => 'Does what the listing says',
        'log' => 'No errors in the log',
        'removes' => 'Removing it leaves nothing behind',
        'screenshots' => 'Screenshots match the item',
    ];

    protected $fillable = [
        'marketplace_item_id', 'version', 'changelog', 'file_path', 'file_size', 'sha256', 'signature', 'manifest', 'checks',
        'checklist', 'status', 'reviewer_id', 'reviewed_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'manifest' => 'array',
            'checks' => 'array',
            'checklist' => 'array',
            'reviewed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<MarketplaceItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(MarketplaceItem::class, 'marketplace_item_id');
    }

    /**
     * @return HasMany<MarketplaceMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(MarketplaceMessage::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reviewer_id');
    }

    public function warnings(): int
    {
        return collect($this->checks ?? [])->whereIn('level', ['warn', 'fail'])->count();
    }

    public function hasFailures(): bool
    {
        return collect($this->checks ?? [])->contains('level', 'fail');
    }

    public function path(): string
    {
        return storage_path('app/private/'.$this->file_path);
    }
}
