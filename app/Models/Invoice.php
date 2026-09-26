<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'number',
        'client_id',
        'status',
        'currency',
        'subtotal',
        'tax',
        'total',
        'amount_paid',
        'issued_at',
        'due_at',
        'paid_at',
        'payment_method',
        'notes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
        'amount_paid' => 0,
        'reminder_count' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal' => 'integer',
            'tax' => 'integer',
            'total' => 'integer',
            'amount_paid' => 'integer',
            'issued_at' => 'immutable_date',
            'due_at' => 'immutable_date',
            'paid_at' => 'datetime',
            'last_reminder_at' => 'datetime',
            'reminder_count' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('id');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class)->orderBy('paid_at');
    }

    public function balance(): int
    {
        return max(0, $this->total - $this->amount_paid);
    }

    public function isPayable(): bool
    {
        return $this->status === InvoiceStatus::Unpaid && $this->balance() > 0;
    }

    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Unpaid && $this->due_at->isPast() && ! $this->due_at->isToday();
    }

    /**
     * Recalculate the totals from the line items. Does not save.
     */
    public function recalculate(): void
    {
        $this->subtotal = (int) $this->items()->sum('amount');
        $this->total = $this->subtotal + $this->tax;
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }
}
