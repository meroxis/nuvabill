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
            'autopay_attempts' => 'integer',
            'autopay_retry_at' => 'datetime',
            'autopay_notice_at' => 'datetime',
            'autopay_pending' => 'array',
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

    /**
     * @return HasMany<CreditNote, $this>
     */
    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class)->orderBy('id');
    }

    /**
     * How much of the invoice credit notes can still take back, in minor units.
     */
    public function creditableAmount(): int
    {
        $credited = $this->relationLoaded('creditNotes') ? $this->creditNotes->sum('total') : $this->creditNotes()->sum('total');

        return max(0, $this->total - (int) $credited);
    }

    /**
     * Money that went back to the client with no credit note for it yet, in minor units: a refund
     * or a dispute at the payment service, or a refund that stopped halfway. Read from the saved
     * payments, so a refund recorded a moment ago counts.
     */
    public function returnedWithoutCreditNote(): int
    {
        $refunded = -(int) Transaction::query()->where('invoice_id', $this->id)->where('type', 'refund')->sum('amount');
        $covered = $this->relationLoaded('creditNotes')
            ? $this->creditNotes->where('method', CreditNote::METHOD_REFUND)->sum('total')
            : $this->creditNotes()->reorder()->where('method', CreditNote::METHOD_REFUND)->sum('total');

        return max(0, $refunded - (int) $covered);
    }

    /**
     * What the client still pays for, in minor units: the total less credit notes, and less money
     * that went back without one.
     */
    public function keptAmount(): int
    {
        return max(0, $this->creditableAmount() - $this->returnedWithoutCreditNote());
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
