<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sets up a new service on its server in the background.
 */
class ProvisionService implements ShouldQueue
{
    use Queueable;

    /**
     * Provisioning is not retried automatically: a failure is logged for staff to check and retry.
     */
    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public Service $service) {}

    public function handle(Provisioner $provisioner): void
    {
        // Staff may have cancelled or terminated the service, or held its order for a review,
        // while this waited in the queue. Only a service still waiting to be set up is set up.
        $service = $this->service->fresh(['order']);

        if ($service === null || $service->status !== ServiceStatus::Pending || $service->order?->needs_review === true) {
            return;
        }

        $provisioner->create($service);
    }
}
