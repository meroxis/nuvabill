<?php

namespace App\Models;

use App\Enums\BillingCycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductAddonPrice extends Model
{
    public $timestamps = false;

    protected $fillable = ['product_addon_id', 'currency', 'billing_cycle', 'price', 'setup_fee'];

    protected function casts(): array
    {
        return [
            'billing_cycle' => BillingCycle::class,
            'price' => 'integer',
            'setup_fee' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ProductAddon, $this>
     */
    public function addon(): BelongsTo
    {
        return $this->belongsTo(ProductAddon::class, 'product_addon_id');
    }
}
