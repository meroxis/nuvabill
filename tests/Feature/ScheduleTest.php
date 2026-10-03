<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use ReflectionClass;
use Tests\TestCase;

/**
 * The scheduled tasks in routes/console.php: their locks and when they run.
 */
class ScheduleTest extends TestCase
{
    public function test_a_crash_never_stops_the_every_minute_jobs_for_a_day(): void
    {
        $worker = $this->event($this->schedule(demo: false), 'queue:work');

        $this->assertTrue($worker->withoutOverlapping);
        // Longer than one worker run (50 seconds plus the longest job), far shorter than a day.
        $this->assertGreaterThan($this->longestJob() + 50, $worker->expiresAt * 60);
        $this->assertLessThanOrEqual(30, $worker->expiresAt);

        $finish = $this->event($this->schedule(demo: false), '--finish-pending');
        $this->assertTrue($finish->withoutOverlapping);
        $this->assertLessThanOrEqual(60, $finish->expiresAt);

        $reset = $this->event($this->schedule(demo: true), 'nuvabill:demo-reset');
        $this->assertTrue($reset->withoutOverlapping);
        $this->assertLessThanOrEqual(60, $reset->expiresAt);
    }

    public function test_a_job_that_is_still_running_is_never_given_to_a_second_worker(): void
    {
        $this->assertGreaterThanOrEqual(900, $this->longestJob(), 'A VPS setup may take minutes.');

        foreach (['database', 'redis', 'beanstalkd'] as $connection) {
            $this->assertGreaterThan($this->longestJob(), config("queue.connections.{$connection}.retry_after"), "The {$connection} queue would give a running job to the next worker.");
        }
    }

    public function test_the_update_is_finished_while_the_site_is_in_maintenance_mode(): void
    {
        $finish = $this->event($this->schedule(demo: false), '--finish-pending');
        $worker = $this->event($this->schedule(demo: false), 'queue:work');

        Artisan::call('down');

        try {
            $this->assertTrue($finish->isDue($this->app), 'An update keeps the site down until it is finished, so the safety net must run then.');
            $this->assertFalse($worker->isDue($this->app), 'Other jobs still wait while the site is down.');
        } finally {
            Artisan::call('up');
        }
    }

    /**
     * The tasks as routes/console.php adds them, on a normal site or on the demo.
     */
    private function schedule(bool $demo): Schedule
    {
        config(['nuvabill.demo' => $demo]);
        $schedule = new Schedule;
        ScheduleFacade::swap($schedule);

        require base_path('routes/console.php');

        return $schedule;
    }

    /**
     * The longest $timeout of the queued jobs, in seconds.
     */
    private function longestJob(): int
    {
        return (int) collect(glob(app_path('Jobs/*.php')))
            ->map(fn (string $file): int => (int) ((new ReflectionClass('App\\Jobs\\'.basename($file, '.php')))->getDefaultProperties()['timeout'] ?? 0))
            ->max();
    }

    private function event(Schedule $schedule, string $command): Event
    {
        $event = collect($schedule->events())->first(fn (Event $event): bool => str_contains((string) $event->command, $command));
        $this->assertNotNull($event, "No scheduled task runs {$command}.");

        return $event;
    }
}
