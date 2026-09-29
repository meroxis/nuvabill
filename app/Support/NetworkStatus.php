<?php

namespace App\Support;

use App\Enums\IncidentStatus;
use App\Enums\ServiceStatus;
use App\Models\Client;
use App\Models\NetworkIncident;
use App\Models\Server;
use Illuminate\Database\Eloquent\Collection;

/**
 * What the network status page and the client area show: servers on the page, open issues,
 * planned maintenance and the one-line summary at the top.
 */
class NetworkStatus
{
    /**
     * Servers staff chose to show, with their current state.
     *
     * @return Collection<int, Server>
     */
    public function publicServers(): Collection
    {
        return Server::query()->where('is_active', true)->where('status_public', true)->orderBy('name')->limit(50)->get();
    }

    /**
     * Issues and maintenance that are not resolved yet, with their updates.
     *
     * @return Collection<int, NetworkIncident>
     */
    public function open(): Collection
    {
        return NetworkIncident::query()->open()->with('updates')->orderByRaw('starts_at IS NULL, starts_at')->orderByDesc('id')->get();
    }

    /**
     * Resolved in the last two weeks.
     *
     * @return Collection<int, NetworkIncident>
     */
    public function recent(int $days = 14): Collection
    {
        return NetworkIncident::query()->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays($days))
            ->with('updates')->latest('resolved_at')->limit(20)->get();
    }

    /**
     * The line at the top of the page: its tone and words.
     *
     * @param  Collection<int, Server>  $servers
     * @param  Collection<int, NetworkIncident>  $open
     * @return array{tone: string, label: string}
     */
    public function summary(Collection $servers, Collection $open): array
    {
        $issues = $open->reject(fn (NetworkIncident $incident): bool => $incident->isMaintenance());
        $working = $open->filter(fn (NetworkIncident $incident): bool => $incident->isMaintenance() && $incident->status === IncidentStatus::InProgress);

        return match (true) {
            $servers->contains(fn (Server $server): bool => $server->status_up === false)
                || $issues->contains(fn (NetworkIncident $incident): bool => $incident->impact === NetworkIncident::IMPACT_MAJOR) => ['tone' => 'crit', 'label' => __('Some services are down')],
            $issues->isNotEmpty() => ['tone' => 'warn', 'label' => __('Some services have problems')],
            $working->isNotEmpty() => ['tone' => 'info', 'label' => __('Planned maintenance is under way')],
            default => ['tone' => 'good', 'label' => __('All services are running')],
        };
    }

    /**
     * Open issues, and maintenance under way or starting within a week, that touch the client's
     * services. Notes without servers touch everyone.
     *
     * @return Collection<int, NetworkIncident>
     */
    public function forClient(Client $client): Collection
    {
        if (! setting('status.enabled')) {
            return new Collection;
        }

        $open = NetworkIncident::query()->open()->latest('id')->limit(20)->get();

        if ($open->isEmpty()) {
            return $open;
        }

        $serverIds = $client->services()
            ->whereIn('status', [ServiceStatus::Active, ServiceStatus::Suspended, ServiceStatus::Pending])
            ->whereNotNull('server_id')
            ->distinct()
            ->pluck('server_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return $open->filter(function (NetworkIncident $incident) use ($serverIds): bool {
            $touches = $incident->serverIds() === [] || array_intersect($incident->serverIds(), $serverIds) !== [];
            $soon = ! $incident->isUpcoming() || $incident->starts_at === null || $incident->starts_at->lte(now()->addDays(7));

            return $touches && $soon;
        })->values();
    }
}
