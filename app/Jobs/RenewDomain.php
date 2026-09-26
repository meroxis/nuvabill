<?php

namespace App\Jobs;

use App\Domains\DomainProvisioner;
use App\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renews a domain at its registrar after the renewal invoice is paid.
 */
class RenewDomain implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public Domain $domain, public int $years) {}

    public function handle(DomainProvisioner $provisioner): void
    {
        $provisioner->renew($this->domain, $this->years);
    }
}
