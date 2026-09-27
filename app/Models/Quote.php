<?php

namespace App\Models;

use App\Billing\Taxes;
use App\Enums\QuoteStatus;
use Database\Factories\QuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A price offer for a client. Staff write it and send it; the client accepts or declines it in
 * the client area. Accepting creates an invoice with the same lines and tax.
 */
class Quote extends Model
{
    /** @use HasFactory<QuoteFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'client_id', 'subject', 'status', 'currency', 'subtotal', 'tax_name', 'tax_rate', 'tax_inclusive',
        'tax', 'total', 'valid_until', 'notes', 'admin_notes', 'invoice_id', 'sent_at', 'accepted_at', 'declined_at',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'subtotal' => 0,
        'tax' => 0,
        'total' => 0,
        'tax_inclusive' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => QuoteStatus::class,
            'subtotal' => 'integer',
            'tax_rate' => 'integer',
            'tax_inclusive' => 'boolean',
            'tax' => 'integer',
            'total' => 'integer',
            'valid_until' => 'immutable_date',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
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
     * @return HasMany<QuoteItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class)->orderBy('id');
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * The status to show: sent quotes past their date are expired.
     */
    public function displayStatus(): QuoteStatus
    {
        return $this->status === QuoteStatus::Sent && $this->isPastValidDate() ? QuoteStatus::Expired : $this->status;
    }

    public function isPastValidDate(): bool
    {
        return $this->valid_until->isPast() && ! $this->valid_until->isToday();
    }

    public function canBeAccepted(): bool
    {
        return $this->status === QuoteStatus::Sent && ! $this->isPastValidDate();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [QuoteStatus::Draft, QuoteStatus::Sent], true);
    }

    /**
     * Totals from the lines with the quote's own tax rate, like {@see Invoice::recalculate()}. Does not save.
     */
    public function recalculate(): void
    {
        $items = $this->items()->get(['amount', 'taxed']);
        $this->subtotal = (int) $items->sum('amount');
        $this->tax = $this->tax_rate !== null ? Taxes::amount((int) $items->where('taxed', true)->sum('amount'), $this->tax_rate, (bool) $this->tax_inclusive) : 0;
        $this->total = $this->tax_inclusive ? $this->subtotal : $this->subtotal + $this->tax;
    }

    public function taxLabel(): ?string
    {
        if ($this->tax_rate === null) {
            return null;
        }

        $label = ($this->tax_name ?: __('Tax')).' ('.TaxRule::formatRate($this->tax_rate).')';

        return $this->tax_inclusive ? __(':tax included', ['tax' => $label]) : $label;
    }

    public function displayNumber(): string
    {
        return $this->number ?? __('Draft quote #:id', ['id' => $this->id]);
    }
}
