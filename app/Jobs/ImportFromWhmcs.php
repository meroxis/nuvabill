<?php

namespace App\Jobs;

use App\Import\Whmcs\WhmcsImporter;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Runs the WHMCS import in the background, a piece at a time: each job works for about 25 seconds
 * (inside the cron queue worker's limit) and then queues the next piece. Progress is kept in the
 * "import.whmcs_status" setting, which the admin import page shows.
 */
class ImportFromWhmcs implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /**
     * Seconds of work per job.
     */
    private const WORK_SECONDS = 25;

    /**
     * A running import with no progress for this long has stopped (for example the worker was killed).
     */
    public const STALLED_AFTER_MINUTES = 10;

    public function __construct(public int $step = 0, public int $afterId = 0) {}

    /**
     * Start the import from the first step. Records imported before are updated, not added again.
     */
    public static function start(): void
    {
        app(Settings::class)->set('import.whmcs_status', [
            'state' => 'running',
            'step' => array_key_first(WhmcsImporter::STEPS),
            'counts' => [],
            'message' => null,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);

        self::dispatch();
    }

    /**
     * @return array{state?: string, step?: string|null, counts?: array<string, array{created: int, updated: int, skipped: int}>, message?: string|null, started_at?: string, updated_at?: string, finished_at?: string|null}
     */
    public static function status(): array
    {
        return (array) setting('import.whmcs_status');
    }

    public static function isRunning(): bool
    {
        $status = self::status();

        return ($status['state'] ?? null) === 'running'
            && now()->subMinutes(self::STALLED_AFTER_MINUTES)->lessThan($status['updated_at'] ?? now());
    }

    public function handle(Settings $settings): void
    {
        $status = self::status();

        if (($status['state'] ?? null) !== 'running') {
            return;
        }

        $steps = array_keys(WhmcsImporter::STEPS);
        $step = $this->step;
        $afterId = $this->afterId;
        $deadline = microtime(true) + self::WORK_SECONDS;

        try {
            $importer = WhmcsImporter::connect((array) setting('import.whmcs'));

            while ($step < count($steps) && microtime(true) < $deadline) {
                $result = $importer->run($steps[$step], $afterId);

                foreach (['created', 'updated', 'skipped'] as $key) {
                    $status['counts'][$steps[$step]][$key] = ($status['counts'][$steps[$step]][$key] ?? 0) + $result[$key];
                }

                [$step, $afterId] = $result['done'] ? [$step + 1, 0] : [$step, $result['last_id']];
            }
        } catch (Throwable $exception) {
            report($exception);
            $settings->set('import.whmcs_status', ['state' => 'failed', 'message' => $exception->getMessage(), 'finished_at' => now()->toIso8601String()] + $status);
            Activity::log('import.failed', 'WHMCS import stopped: '.$exception->getMessage());

            return;
        }

        // Staff may have cancelled the import while this piece ran.
        $settings->flush();

        if ((self::status()['state'] ?? null) !== 'running') {
            return;
        }

        $finished = $step >= count($steps);

        $settings->set('import.whmcs_status', [
            'state' => $finished ? 'done' : 'running',
            'step' => $steps[$step] ?? null,
            'updated_at' => now()->toIso8601String(),
            'finished_at' => $finished ? now()->toIso8601String() : null,
        ] + $status);

        if ($finished) {
            Activity::log('import.finished', 'WHMCS import finished.');

            return;
        }

        self::dispatch($step, $afterId);
    }
}
