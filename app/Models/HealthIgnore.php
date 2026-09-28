<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A site health check that staff chose to ignore, and why.
 *
 * @property int $id
 * @property string $check_id
 * @property string $reason
 * @property int|null $admin_id
 * @property Carbon $created_at
 */
class HealthIgnore extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['check_id', 'reason', 'admin_id'];

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }
}
