<?php

namespace App\Jobs;

use App\Automations\Runner;
use App\Models\AutomationRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Does the steps of a new automation run in the background, so a payment or a sign-up never
 * waits for emails or web addresses. Waits are picked up later by `nuvabill:automations`.
 */
class ContinueAutomationRun implements ShouldQueue
{
    use Queueable;

    /**
     * A failed step is shown on the automation page instead of being tried again.
     */
    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $runId) {}

    public function handle(Runner $runner): void
    {
        $run = AutomationRun::query()->find($this->runId);

        if ($run !== null) {
            $runner->continue($run);
        }
    }

    /**
     * The worker gave up on the job: it took longer than the timeout, or the worker stopped. A run
     * left "running" is marked failed, so staff see it and its remaining steps never half-happen.
     */
    public function failed(?Throwable $exception): void
    {
        app(Runner::class)->interrupted($this->runId);
    }
}
