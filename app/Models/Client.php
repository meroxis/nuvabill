<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use Database\Factories\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;

/**
 * A customer of the hosting company. Signs in to the client area.
 */
class Client extends Authenticatable
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'company_name',
        'email',
        'password',
        'phone',
        'address_1',
        'address_2',
        'city',
        'state',
        'postcode',
        'country',
        'currency',
        'status',
        'notes',
        'tax_exempt',
        'tax_id',
        'tags',
    ];

    public const TWO_FACTOR_APP = 'totp';

    public const TWO_FACTOR_EMAIL = 'email';

    protected $hidden = [
        'password',
        'legacy_password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'credit' => 0,
        'status' => 'active',
        'has_password' => true,
        'auto_pay' => true,
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => ClientStatus::class,
            'erased_at' => 'datetime',
            'credit' => 'integer',
            'tax_exempt' => 'boolean',
            'has_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'tags' => 'array',
            'auto_pay' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // A password set on purpose (by staff, by the client or through a reset) replaces an imported
        // one for good, so the password from the old billing system stops working.
        static::saving(function (Client $client): void {
            if ($client->exists && $client->isDirty('password') && ! $client->isDirty('legacy_password')) {
                $client->legacy_password = null;
            }

            // A new email address is not confirmed yet, whoever changed it, so Google or GitHub
            // sign-in cannot take over the account through it.
            if ($client->exists && $client->isDirty('email') && ! $client->isDirty('email_verified_at')) {
                $client->email_verified_at = null;
            }
        });
    }

    /**
     * Whether staff erased this client's personal data. The name and address stay only when
     * invoices need them.
     */
    public function isErased(): bool
    {
        return $this->erased_at !== null;
    }

    /**
     * Cards and PayPal accounts saved to pay renewals automatically.
     *
     * @return HasMany<PaymentMethod, $this>
     */
    public function paymentMethods(): HasMany
    {
        return $this->hasMany(PaymentMethod::class);
    }

    /**
     * The saved method that pays renewals, when the client has one.
     */
    public function defaultPaymentMethod(): ?PaymentMethod
    {
        return $this->paymentMethods()->orderByDesc('is_default')->latest('id')->first();
    }

    /**
     * Staff labels such as "VIP" or "Reseller", used in automations and on the client page.
     *
     * @return list<string>
     */
    public function tagList(): array
    {
        return self::cleanTags($this->tags);
    }

    public function hasTag(string $tag): bool
    {
        return in_array(mb_strtolower(trim($tag)), array_map('mb_strtolower', $this->tagList()), true);
    }

    /**
     * Tags from a list or a comma-separated text: trimmed, at most 30 letters each, no doubles, at most 20.
     *
     * @param  iterable<mixed>|string|null  $tags
     * @return list<string>
     */
    public static function cleanTags(iterable|string|null $tags): array
    {
        $list = is_string($tags) ? explode(',', $tags) : (array) $tags;
        $clean = [];

        foreach ($list as $tag) {
            $tag = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $tag)), 0, 30);

            if ($tag !== '' && ! in_array(mb_strtolower($tag), array_map('mb_strtolower', $clean), true)) {
                $clean[] = $tag;
            }
        }

        return array_slice($clean, 0, 20);
    }

    /**
     * @return Attribute<string, never>
     */
    protected function name(): Attribute
    {
        return Attribute::get(fn (): string => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Emails are saved in lowercase, so "Raz@Example.com" and "raz@example.com" are one mailbox and
     * one account on every database (SQLite compares letter case).
     *
     * @return Attribute<never, ?string>
     */
    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : Str::lower(trim($value)));
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /**
     * @return HasMany<Invoice, $this>
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    /**
     * @return HasMany<Quote, $this>
     */
    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class);
    }

    /**
     * Changes to the wallet balance, newest last.
     *
     * @return HasMany<CreditTransaction, $this>
     */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    /**
     * @return HasMany<SocialAccount, $this>
     */
    public function passkeys(): MorphMany
    {
        return $this->morphMany(Passkey::class, 'owner');
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(SocialAccount::class);
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /**
     * Two-factor sign-in is set up and confirmed (with an authenticator app or email codes).
     */
    /**
     * The client's developer account on the marketplace store, if they sell there.
     *
     * @return HasOne<Developer, $this>
     */
    public function developer(): HasOne
    {
        return $this->hasOne(Developer::class);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return in_array($this->two_factor_method, [self::TWO_FACTOR_APP, self::TWO_FACTOR_EMAIL], true)
            && $this->two_factor_confirmed_at !== null
            && ($this->two_factor_method === self::TWO_FACTOR_EMAIL || $this->two_factor_secret !== null);
    }

    public function unpaidInvoicesTotal(): int
    {
        return (int) $this->invoices()
            ->where('status', InvoiceStatus::Unpaid)
            ->selectRaw('coalesce(sum(total - amount_paid), 0) as balance')
            ->value('balance');
    }

    public function activeServicesCount(): int
    {
        return $this->services()->where('status', ServiceStatus::Active)->count();
    }

    public function openTicketsCount(): int
    {
        return $this->tickets()->where('status', '!=', TicketStatus::Closed)->count();
    }

    /**
     * @param  Builder<Client>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $query->where(function (Builder $query) use ($term): void {
            $like = '%'.$term.'%';
            $query->where('first_name', 'like', $like)
                ->orWhere('last_name', 'like', $like)
                ->orWhere('company_name', 'like', $like)
                ->orWhere('email', 'like', $like);

            if (ctype_digit($term)) {
                $query->orWhere('id', (int) $term);
            }
        });
    }
}
