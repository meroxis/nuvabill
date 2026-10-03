<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A license key for a paid marketplace item, sold on the store. It is tied to one site (host name)
 * the first time it is used. Updates are included until updates_until; the item keeps working after.
 */
class License extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    /**
     * How often a client may move a key to a new site by themselves, per year.
     */
    public const SITE_CHANGES_PER_YEAR = 3;

    /**
     * Sites that never need a license of their own: local and test copies.
     *
     * @var list<string>
     */
    public const TEST_SITE_PATTERNS = ['localhost', '127.0.0.1', '*.test', '*.local', '*.localhost', '*.example', 'staging.*', 'dev.*'];

    protected $fillable = ['key', 'marketplace_item_id', 'client_id', 'service_id', 'site', 'site_changes', 'site_changes_since', 'status', 'updates_until', 'revoked_reason', 'last_seen_at'];

    protected function casts(): array
    {
        return [
            'site_changes' => 'integer',
            'site_changes_since' => 'datetime',
            'updates_until' => 'immutable_date',
            'last_seen_at' => 'datetime',
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
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return HasMany<LicenseCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(LicenseCheck::class);
    }

    /**
     * A new key like NVB-7K2P-94QX-M3TA-8RWD. No 0/O or 1/I, so keys are easy to read out.
     */
    public static function newKey(): string
    {
        $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

        do {
            $groups = [];

            for ($group = 0; $group < 4; $group++) {
                $chars = '';

                for ($i = 0; $i < 4; $i++) {
                    $chars .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                }

                $groups[] = $chars;
            }

            $key = 'NVB-'.implode('-', $groups);
        } while (self::query()->where('key', $key)->exists());

        return $key;
    }

    public static function normalizeSite(string $site): string
    {
        $site = strtolower(trim($site));
        $site = (string) (parse_url(str_contains($site, '://') ? $site : 'https://'.$site, PHP_URL_HOST) ?: $site);

        return (string) preg_replace('/^www\./', '', $site);
    }

    public static function isTestSite(string $site): bool
    {
        return Str::is(self::TEST_SITE_PATTERNS, self::normalizeSite($site));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function includesUpdatesOn(\DateTimeInterface $date): bool
    {
        return $this->updates_until === null || $this->updates_until->endOfDay()->gte($date);
    }

    public function publicId(): string
    {
        return 'L-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
