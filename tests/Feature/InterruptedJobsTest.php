<?php

namespace Tests\Feature;

use App\Automations\Runner;
use App\Enums\DomainStatus;
use App\Enums\ServiceStatus;
use App\Jobs\ContinueAutomationRun;
use App\Jobs\ProvisionService;
use App\Jobs\RegisterDomain;
use App\Jobs\RenewDomain;
use App\Models\ActivityLog;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Background work that was cut off (the job took too long, or the worker stopped) is marked
 * failed or logged, so staff see it, instead of staying "running" or "pending" without a word.
 */
class InterruptedJobsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_whose_job_took_too_long_is_marked_failed(): void
    {
        $running = $this->automationRun(AutomationRun::RUNNING);
        $done = $this->automationRun(AutomationRun::DONE);

        (new ContinueAutomationRun($running->id))->failed(new TimeoutExceededException('Timed out.'));
        (new ContinueAutomationRun($done->id))->failed(new TimeoutExceededException('Timed out.'));

        $running->refresh();
        $this->assertSame(AutomationRun::FAILED, $running->status);
        $this->assertStringContainsString('Interrupted while working on step 2', (string) $running->error);
        $this->assertNotNull($running->finished_at);
        $this->assertSame(1, collect($running->log)->last()['step']);
        $this->assertSame(AutomationRun::DONE, $done->refresh()->status, 'A finished run is left as it is.');
    }

    public function test_the_next_worker_marks_a_run_failed_when_the_worker_doing_it_died(): void
    {
        config(['queue.default' => 'database']);
        $run = $this->automationRun(AutomationRun::RUNNING);
        ContinueAutomationRun::dispatch($run->id);
        // The worker that took the job twenty minutes ago (longer than any job may run) never finished it.
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->subMinutes(20)->getTimestamp()]);

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        $this->assertSame(AutomationRun::FAILED, $run->refresh()->status);
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(0, DB::table('jobs')->count());
    }

    public function test_a_long_vps_setup_that_is_still_running_is_left_to_its_worker(): void
    {
        config(['queue.default' => 'database']);
        $service = Service::factory()->pending()->create();
        ProvisionService::dispatch($service);
        // A slow disk clone: the first worker took the job eleven minutes ago and still waits on the node.
        DB::table('jobs')->update(['attempts' => 1, 'reserved_at' => now()->subMinutes(11)->getTimestamp()]);

        $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true, '--tries' => 1])->assertSuccessful();

        $this->assertSame(1, DB::table('jobs')->count(), 'The running job stays with the worker doing it.');
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertDatabaseMissing('activity_logs', ['action' => 'service.module_failed', 'subject_id' => $service->id]);
    }

    public function test_runs_left_running_for_a_long_time_are_marked_failed_by_the_regular_check(): void
    {
        $stale = $this->automationRun(AutomationRun::RUNNING);
        $busy = $this->automationRun(AutomationRun::RUNNING);
        DB::table('automation_runs')->where('id', $stale->id)->update(['updated_at' => now()->subHour()]);
        DB::table('automation_runs')->where('id', $busy->id)->update(['updated_at' => now()->subMinute()]);

        app(Runner::class)->resumeDue();

        $stale->refresh();
        $this->assertSame(AutomationRun::FAILED, $stale->status);
        $this->assertStringContainsString('Interrupted', (string) $stale->error);
        $this->assertNotNull($stale->finished_at);
        $this->assertSame(AutomationRun::RUNNING, $busy->refresh()->status, 'A run that is still being worked on is left alone.');
    }

    public function test_a_service_setup_that_was_cut_off_is_logged_for_staff(): void
    {
        $pending = Service::factory()->create(['status' => ServiceStatus::Pending]);
        $active = Service::factory()->create(['status' => ServiceStatus::Active]);

        (new ProvisionService($pending))->failed(new TimeoutExceededException('Timed out.'));
        (new ProvisionService($active))->failed(new TimeoutExceededException('Timed out.'));

        $log = ActivityLog::query()->where('action', 'service.module_failed')->sole();
        $this->assertSame($pending->id, $log->subject_id);
        $this->assertStringContainsString('Timed out.', $log->description);
    }

    public function test_a_registrar_call_that_was_cut_off_is_logged_for_staff(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz']);
        $pending = Domain::factory()->for($client)->pending()->create(['name' => 'raz-shop.com']);
        $active = Domain::factory()->for($client)->create(['name' => 'raz-blog.com']);

        (new RegisterDomain($pending))->failed(new TimeoutExceededException('Timed out.'));
        (new RegisterDomain($active))->failed(new TimeoutExceededException('Timed out.'));
        (new RenewDomain($active, 1))->failed(new TimeoutExceededException('Timed out.'));

        $logs = ActivityLog::query()->where('action', 'domain.registrar_failed')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertStringContainsString('Could not register raz-shop.com', $logs[0]->description);
        $this->assertStringContainsString('Could not renew raz-blog.com', $logs[1]->description);
        $this->assertSame($client->id, $logs[1]->client_id);
        $this->assertSame(DomainStatus::Active, $active->fresh()->status);
    }

    private function automationRun(string $status): AutomationRun
    {
        $automation = Automation::query()->firstOrCreate(['name' => 'Welcome'], [
            'trigger' => 'client.registered',
            'steps' => [
                ['type' => 'tag_client', 'config' => ['tag' => 'new']],
                ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'body' => 'Hello.']],
            ],
            'is_active' => true,
        ]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);

        return AutomationRun::query()->forceCreate([
            'automation_id' => $automation->id,
            'subject_type' => $client->getMorphClass(),
            'subject_id' => $client->id,
            'client_id' => $client->id,
            'status' => $status,
            'step' => 1,
            'dedupe_key' => 'a'.$automation->id.':client:'.$client->id.':once',
            'log' => [['step' => null, 'text' => 'Started: A client signs up', 'at' => now()->toIso8601String()]],
        ]);
    }
}
