<?php

namespace App\Models;

use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
