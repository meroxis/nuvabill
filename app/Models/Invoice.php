<?php

namespace App\Models;

use App\Billing\Taxes;
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
        'tax_name',
        'tax_rate',
        'tax_inclusive',
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
            'tax_rate' => 'integer',
            'tax_inclusive' => 'boolean',
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
     * Recalculate the totals from the line items with the tax rate the invoice was created with.
     * Invoices without a rate (for example imported ones) keep the tax they have. Does not save.
     */
    public function recalculate(): void
    {
        $items = $this->items()->get(['amount', 'taxed']);
        $this->subtotal = (int) $items->sum('amount');

        if ($this->tax_rate !== null) {
            $this->tax = Taxes::amount((int) $items->where('taxed', true)->sum('amount'), $this->tax_rate, (bool) $this->tax_inclusive);
        }

        $this->total = $this->tax_inclusive ? $this->subtotal : $this->subtotal + $this->tax;
    }

    /**
     * The tax line as shown on invoices, for example "VAT (20%)" or "VAT (20%) included", or null when there is no tax.
     */
    public function taxLabel(): ?string
    {
        if ($this->tax_rate === null && $this->tax === 0) {
            return null;
        }

        $name = $this->tax_name ?: __('Tax');
        $label = $this->tax_rate !== null ? $name.' ('.TaxRule::formatRate($this->tax_rate).')' : $name;

        return $this->tax_inclusive ? __(':tax included', ['tax' => $label]) : $label;
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Draft #:id', ['id' => $this->id]);
    }
}
