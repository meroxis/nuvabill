<?php

namespace App\Jobs;

use App\Enums\ServiceStatus;
use App\Models\Service;
use App\Provisioning\Provisioner;
use App\Support\Activity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

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

    /**
     * Seconds before the worker stops the job. A VPS module may wait minutes for a full disk
     * clone, and stops its own work in time to clean up within this.
     */
    public int $timeout = 900;

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

    /**
     * The worker stopped the job, for example after the time limit. Log it, so staff see
     * the service was not set up.
     */
    public function failed(?Throwable $exception): void
    {
        Activity::log('service.module_failed', "Could not create service #{$this->service->id} ({$this->service->label()}): the setup job stopped. ".$exception?->getMessage(), $this->service);
    }
}
