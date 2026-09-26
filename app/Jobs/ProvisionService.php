<?php

namespace App\Jobs;

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
        $provisioner->create($this->service);
    }
}
