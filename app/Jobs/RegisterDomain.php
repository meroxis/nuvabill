<?php

namespace App\Jobs;

use App\Domains\DomainProvisioner;
use App\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Registers (or starts the transfer of) a paid domain in the background.
 */
class RegisterDomain implements ShouldQueue
{
    use Queueable;

    /**
     * Not retried automatically: a failure is logged for staff to check and retry.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Domain $domain) {}

    public function handle(DomainProvisioner $provisioner): void
    {
        $provisioner->register($this->domain);
    }
}
