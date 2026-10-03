<?php

namespace App\Jobs;

use App\Domains\DomainProvisioner;
use App\Models\Domain;
use App\Support\Activity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * The job took too long or the worker stopped before the registrar answered, so the usual
     * failure log never ran. Log it here, so staff check the renewal at the registrar.
     */
    public function failed(?Throwable $exception): void
    {
        $domain = $this->domain->fresh(['client']);

        if ($domain !== null) {
            Activity::log('domain.registrar_failed', "Could not renew {$domain->name}: it was interrupted before it finished, so check the domain at the registrar (".Str::limit($exception?->getMessage() ?: 'stopped', 160).')', $domain, $domain->client);
        }
    }
}
