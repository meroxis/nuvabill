<?php

namespace App\Jobs;

use App\Domains\DomainProvisioner;
use App\Enums\DomainStatus;
use App\Models\Domain;
use App\Support\Activity;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * The job took too long or the worker stopped before the registrar answered, so the usual
     * failure log never ran. Log it here, while the domain still waits to be registered.
     */
    public function failed(?Throwable $exception): void
    {
        $domain = $this->domain->fresh(['client']);

        if ($domain !== null && in_array($domain->status, [DomainStatus::Pending, DomainStatus::PendingTransfer], true)) {
            Activity::log('domain.registrar_failed', "Could not register {$domain->name}: it was interrupted before it finished, so check the domain at the registrar (".Str::limit($exception?->getMessage() ?: 'stopped', 160).')', $domain, $domain->client);
        }
    }
}
