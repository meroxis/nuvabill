<?php

namespace App\Models;

use App\Support\Activity;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A staff member who signs in to the admin area.
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'role_id',
        'name',
        'email',
        'password',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'push_alerts' => 'array',
        ];
    }

    /**
     * @return HasMany<PushSubscription, $this>
     */
    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /**
     * The phone alerts this staff member wants, limited to what their role may see.
     *
     * @return array{orders: bool, payments: bool, payments_over: int, tickets: bool, replies: bool}
     */
    public function pushAlerts(): array
    {
        $saved = (array) $this->push_alerts;

        return [
            'orders' => (bool) ($saved['orders'] ?? true) && $this->hasPermission('orders.manage'),
            'payments' => (bool) ($saved['payments'] ?? true) && $this->hasPermission('billing.view'),
            'payments_over' => max(0, (int) ($saved['payments_over'] ?? 0)),
            'tickets' => (bool) ($saved['tickets'] ?? true) && $this->hasPermission('support.manage'),
            'replies' => (bool) ($saved['replies'] ?? true) && $this->hasPermission('support.manage'),
        ];
    }

    /**
     * @return BelongsTo<Role, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function hasPermission(string $permission): bool
    {
        return $this->is_active && $this->role?->allows($permission) === true;
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function initials(): string
    {
        return collect(explode(' ', trim($this->name)))
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * Keys for the REST API that act as this staff member.
     *
     * @return HasMany<ApiToken, $this>
     */
    public function passkeys(): MorphMany
    {
        return $this->morphMany(Passkey::class, 'owner');
    }

    public function apiTokens(): HasMany
    {
        return $this->hasMany(ApiToken::class);
    }

    /**
     * Delete all of this staff member's API keys, for example after a new password, so a stolen key
     * stops working too. Writes one activity log entry and returns how many keys were deleted.
     */
    public function revokeApiKeys(string $reason, ?Model $actor = null): int
    {
        $count = $this->apiTokens()->delete();

        if ($count > 0) {
            Activity::log('api.keys_revoked', "{$count} API key(s) of {$this->name} deleted: {$reason}", $this, actor: $actor);
        }

        return $count;
    }

    /**
     * Delete all of this staff member's passkeys, for example after a new password: a passkey signs
     * in without the password and without a two-factor code. Writes one activity log entry and
     * returns how many passkeys were deleted.
     */
    public function revokePasskeys(string $reason, ?Model $actor = null): int
    {
        $count = (int) $this->passkeys()->delete();

        if ($count > 0) {
            Activity::log('admin.passkeys_revoked', "{$count} passkey(s) of {$this->name} deleted: {$reason}", $this, actor: $actor);
        }

        return $count;
    }

    /**
     * Stop phone alerts on all of this staff member's devices, for example after a new password, so
     * a device someone else added no longer gets them. Writes one activity log entry and returns
     * how many devices were removed.
     */
    public function revokePushDevices(string $reason, ?Model $actor = null): int
    {
        $count = (int) $this->pushSubscriptions()->delete();

        if ($count > 0) {
            Activity::log('admin.push_revoked', "{$count} phone alert device(s) of {$this->name} removed: {$reason}", $this, actor: $actor);
        }

        return $count;
    }

    /**
     * After a password reset, or a password set by another staff member, nothing else may keep
     * working for whoever had the account before: API keys, passkeys and phone alert devices all go.
     *
     * @return array{api_keys: int, passkeys: int, push_devices: int}
     */
    public function revokeOtherAccess(string $reason, ?Model $actor = null): array
    {
        return [
            'api_keys' => $this->revokeApiKeys($reason, $actor),
            'passkeys' => $this->revokePasskeys($reason, $actor),
            'push_devices' => $this->revokePushDevices($reason, $actor),
        ];
    }
}
