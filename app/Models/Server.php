<?php

namespace App\Models;

use App\Enums\ServiceStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A control panel server (cPanel/WHM, DirectAdmin, ...) that services are created on.
 */
class Server extends Model
{
    /** @use HasFactory<ServerFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'module',
        'hostname',
        'ip_address',
        'port',
        'use_ssl',
        'username',
        'password',
        'api_token',
        'nameservers',
        'max_accounts',
        'is_active',
    ];

    protected $hidden = ['password', 'api_token'];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
            'api_token' => 'encrypted',
            'nameservers' => 'array',
            'use_ssl' => 'boolean',
            'is_active' => 'boolean',
            'port' => 'integer',
            'max_accounts' => 'integer',
        ];
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function accountsCount(): int
    {
        return $this->services()->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])->count();
    }

    public function hasCapacity(): bool
    {
        return $this->max_accounts === null || $this->accountsCount() < $this->max_accounts;
    }
}
