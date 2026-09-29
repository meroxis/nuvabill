<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Database\Factories\NetworkIncidentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A problem on the network, or planned maintenance, shown on the network status page and to the
 * clients whose services are on the servers it names. No servers means it affects everyone.
 */
class NetworkIncident extends Model
{
    /** @use HasFactory<NetworkIncidentFactory> */
    use HasFactory;

    public const KIND_ISSUE = 'issue';

    public const KIND_MAINTENANCE = 'maintenance';

    public const IMPACT_MINOR = 'minor';

    public const IMPACT_MAJOR = 'major';

    protected $fillable = ['title', 'kind', 'status', 'impact', 'server_ids', 'starts_at', 'ends_at', 'resolved_at'];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'server_ids' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * Newest first.
     *
     * @return HasMany<NetworkIncidentUpdate, $this>
     */
    public function updates(): HasMany
    {
        return $this->hasMany(NetworkIncidentUpdate::class)->latest('id');
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }

    public function isMaintenance(): bool
    {
        return $this->kind === self::KIND_MAINTENANCE;
    }

    /**
     * Planned work that has not started yet.
     */
    public function isUpcoming(): bool
    {
        return $this->isMaintenance() && $this->isOpen() && $this->status === IncidentStatus::Scheduled;
    }

    /**
     * @return list<int>
     */
    public function serverIds(): array
    {
        return array_values(array_map('intval', (array) ($this->server_ids ?? [])));
    }

    /**
     * @return Collection<int, Server>
     */
    public function servers(): Collection
    {
        $ids = $this->serverIds();

        return $ids === [] ? new Collection : Server::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    public static function kindLabel(string $kind): string
    {
        return $kind === self::KIND_MAINTENANCE ? __('Planned maintenance') : __('Network issue');
    }

    public static function impactLabel(string $impact): string
    {
        return $impact === self::IMPACT_MAJOR ? __('Services are down') : __('Some services are slow or partly down');
    }
}
