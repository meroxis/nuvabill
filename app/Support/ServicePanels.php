<?php

namespace App\Support;

use App\Models\Service;
use Closure;
use Throwable;

/**
 * Extra boxes on the client's service page, added by features and add-ons. For example the
 * marketplace store shows the license key of a theme the client bought.
 *
 * A resolver gets the service and returns ['view' => 'name', 'data' => [...]] or null.
 */
class ServicePanels
{
    /**
     * @var list<Closure(Service): (array{view: string, data?: array<string, mixed>}|null)>
     */
    private array $resolvers = [];

    /**
     * @param  Closure(Service): (array{view: string, data?: array<string, mixed>}|null)  $resolver
     */
    public function add(Closure $resolver): void
    {
        $this->resolvers[] = $resolver;
    }

    /**
     * @return list<array{view: string, data: array<string, mixed>}>
     */
    public function for(Service $service): array
    {
        $panels = [];

        foreach ($this->resolvers as $resolver) {
            try {
                $panel = $resolver($service);
            } catch (Throwable $exception) {
                report($exception);
                $panel = null;
            }

            if (is_array($panel) && isset($panel['view'])) {
                $panels[] = ['view' => $panel['view'], 'data' => $panel['data'] ?? []];
            }
        }

        return $panels;
    }
}
