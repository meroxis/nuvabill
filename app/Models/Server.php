<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use Database\Factories\ServerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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
        'status_public',
        'status_name',
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
            'status_public' => 'boolean',
            'status_up' => 'boolean',
            'status_failures' => 'integer',
            'status_checked_at' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * The services that take an account on the server: live ones, and new ones once their order is
     * paid or accepted (or made by staff without an order). An unpaid order takes no room, so
     * orders nobody pays cannot fill the server.
     *
     * @return HasMany<Service, $this>
     */
    public function accounts(): HasMany
    {
        return $this->services()->where(fn (Builder $query) => $query
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended])
            ->orWhere(fn (Builder $query) => $query
                ->where('status', ServiceStatus::Pending)
                ->where(fn (Builder $query) => $query
                    ->whereNull('order_id')
                    ->orWhereHas('order', fn (Builder $query) => $query->where('status', OrderStatus::Active)))));
    }

    public function accountsCount(): int
    {
        return $this->accounts()->count();
    }

    public function hasCapacity(): bool
    {
        return $this->max_accounts === null || $this->accountsCount() < $this->max_accounts;
    }

    /**
     * @return HasMany<ServerCheck, $this>
     */
    public function checks(): HasMany
    {
        return $this->hasMany(ServerCheck::class);
    }

    /**
     * The name shown on the network status page.
     */
    public function publicName(): string
    {
        return filled($this->status_name) ? (string) $this->status_name : (string) $this->name;
    }

    /**
     * Share of checks the server answered in the last days, as a percentage, per server. Null for
     * a server without checks.
     *
     * @param  list<int>  $serverIds
     * @return array<int, float>
     */
    public static function uptime(array $serverIds, int $days = 30): array
    {
        if ($serverIds === []) {
            return [];
        }

        return ServerCheck::query()
            ->whereIn('server_id', $serverIds)
            ->where('checked_at', '>=', now()->subDays($days))
            ->groupBy('server_id')
            ->get(['server_id', DB::raw('count(*) as total'), DB::raw('sum(case when is_up then 1 else 0 end) as up')])
            ->mapWithKeys(fn (ServerCheck $row): array => [(int) $row->server_id => round((int) $row->getAttribute('up') * 100 / max(1, (int) $row->getAttribute('total')), 2)])
            ->all();
    }

    /**
     * Uptime per day for the bars on the status page: date => percentage, oldest first, null for
     * days without checks.
     *
     * @return array<string, float|null>
     */
    public function dailyUptime(int $days = 30): array
    {
        $rows = $this->checks()
            ->where('checked_at', '>=', now()->subDays($days - 1)->startOfDay())
            ->groupBy(DB::raw('date(checked_at)'))
            ->get([DB::raw('date(checked_at) as day'), DB::raw('count(*) as total'), DB::raw('sum(case when is_up then 1 else 0 end) as up')])
            ->mapWithKeys(fn (ServerCheck $row): array => [(string) $row->getAttribute('day') => round((int) $row->getAttribute('up') * 100 / max(1, (int) $row->getAttribute('total')), 2)]);
        $result = [];

        for ($day = $days - 1; $day >= 0; $day--) {
            $date = now()->subDays($day)->toDateString();
            $result[$date] = $rows[$date] ?? null;
        }

        return $result;
    }
}
