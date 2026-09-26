<?php

namespace App\Models;

use App\Enums\DomainStatus;
use Database\Factories\DomainFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A domain name a client registered or transferred through us.
 */
class Domain extends Model
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    public const TYPE_REGISTER = 'register';

    public const TYPE_TRANSFER = 'transfer';

    protected $fillable = [
        'client_id',
        'order_id',
        'name',
        'tld',
        'registrar',
        'order_type',
        'status',
        'years',
        'currency',
        'first_payment_amount',
        'recurring_amount',
        'registered_at',
        'expires_at',
        'next_due_date',
        'auto_renew',
        'nameservers',
        'epp_code',
        'registrar_data',
        'last_synced_at',
        'expiry_notice_sent_at',
    ];

    protected $hidden = ['epp_code'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'years' => 1,
        'auto_renew' => true,
        'first_payment_amount' => 0,
        'recurring_amount' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => DomainStatus::class,
            'years' => 'integer',
            'first_payment_amount' => 'integer',
            'recurring_amount' => 'integer',
            'registered_at' => 'immutable_date',
            'expires_at' => 'immutable_date',
            'next_due_date' => 'immutable_date',
            'auto_renew' => 'boolean',
            'nameservers' => 'array',
            'epp_code' => 'encrypted',
            'registrar_data' => 'array',
            'last_synced_at' => 'datetime',
            'expiry_notice_sent_at' => 'datetime',
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
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function invoiceItems(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @param  Builder<Domain>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }
    }

    /**
     * The name without its extension, for example "example" for example.com.
     */
    public function sld(): string
    {
        return substr($this->name, 0, -strlen($this->tld) - 1);
    }

    public function isTransfer(): bool
    {
        return $this->order_type === self::TYPE_TRANSFER;
    }

    /**
     * @return list<string>
     */
    public function nameserverList(): array
    {
        return array_values(array_filter((array) $this->nameservers, fn (mixed $ns): bool => is_string($ns) && $ns !== ''));
    }

    /**
     * A value the registrar module saved, for example its own order ID.
     */
    public function registrarValue(string $key, mixed $default = null): mixed
    {
        return $this->registrar_data[$key] ?? $default;
    }
}
