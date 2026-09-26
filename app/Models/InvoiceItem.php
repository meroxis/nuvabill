<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceItem extends Model
{
    public const TYPE_SERVICE = 'service';

    public const TYPE_SETUP = 'setup';

    public const TYPE_MANUAL = 'manual';

    protected $fillable = ['invoice_id', 'service_id', 'type', 'description', 'amount', 'period_start', 'period_end'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'period_start' => 'immutable_date',
            'period_end' => 'immutable_date',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }
}
