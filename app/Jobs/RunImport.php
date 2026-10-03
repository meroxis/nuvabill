<?php

namespace App\Jobs;

use App\Import\ImportSources;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs an import in the background, a piece at a time: each job works for about 25 seconds (inside the
 * cron queue worker's limit) and then queues the next piece. Progress is kept in the "import.status"
 * setting, which the admin import page shows.
 */
class RunImport implements ShouldQueue
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

    /**
     * Rows that could not be imported, kept for the import page. The counts include all of them.
     */
    private const KEEP_ERRORS = 50;

    /**
     * The run this piece belongs to. Null for pieces queued before 0.6.12 (declared with a default, so
     * those still unserialize).
     */
    public ?string $runId = null;

    public function __construct(public int $step = 0, public int $afterId = 0, ?string $runId = null)
    {
        $this->runId = $runId;
    }

    /**
     * Start the import from the first step. Records imported before are updated, not added again.
     */
    public static function start(string $source): void
    {
        $runId = (string) Str::uuid();

        app(Settings::class)->set('import.status', [
            'state' => 'running',
            'run_id' => $runId,
            'source' => $source,
            'step' => array_key_first(ImportSources::get($source)::steps()),
            'counts' => [],
            'errors' => [],
            'message' => null,
            'started_at' => now()->toIso8601String(),
            'updated_at' => now()->toIso8601String(),
            'finished_at' => null,
        ]);

        static::dispatch(0, 0, $runId);
    }

    /**
     * @return array{state?: string, source?: string, step?: string|null, counts?: array<string, array{created: int, updated: int, skipped: int}>, errors?: list<array{step: string, id: int, error: string}>, message?: string|null, started_at?: string, updated_at?: string, finished_at?: string|null}
     */
    public static function status(): array
    {
        return (array) (setting('import.status') ?? setting('import.whmcs_status'));
    }

    public static function isRunning(): bool
    {
        $status = self::status();

        return ($status['state'] ?? null) === 'running'
            && now()->subMinutes(self::STALLED_AFTER_MINUTES)->lessThan($status['updated_at'] ?? now());
    }

    /**
     * Whether this piece belongs to the import that is running now. A piece still queued from a stopped or
     * stalled run would otherwise carry on inside a new run, from its own step and row.
     *
     * @param  array<string, mixed>  $status
     */
    private function isCurrent(array $status): bool
    {
        return ($status['state'] ?? null) === 'running' && ($status['run_id'] ?? null) === $this->runId;
    }

    public function handle(Settings $settings): void
    {
        $status = self::status();

        if (! $this->isCurrent($status)) {
            return;
        }

        $step = $this->step;
        $afterId = $this->afterId;
        $deadline = microtime(true) + self::WORK_SECONDS;

        try {
            $connection = ImportSources::connection();
            $importer = ImportSources::connect(['source' => $status['source'] ?? 'whmcs'] + $connection);
            $steps = array_keys($importer::steps());

            while ($step < count($steps) && microtime(true) < $deadline) {
                $result = $importer->run($steps[$step], $afterId);

                foreach (['created', 'updated', 'skipped'] as $key) {
                    $status['counts'][$steps[$step]][$key] = ($status['counts'][$steps[$step]][$key] ?? 0) + $result[$key];
                }

                foreach ($result['errors'] as $error) {
                    $status['errors'][] = ['step' => $steps[$step]] + $error;
                }

                $status['errors'] = array_slice($status['errors'] ?? [], -self::KEEP_ERRORS);
                [$step, $afterId] = $result['done'] ? [$step + 1, 0] : [$step, $result['last_id']];
            }
        } catch (Throwable $exception) {
            report($exception);
            $settings->flush();

            // Staff may have stopped this run and started another while this piece ran.
            if (! $this->isCurrent(self::status())) {
                return;
            }

            $settings->set('import.status', ['state' => 'failed', 'message' => $exception->getMessage(), 'finished_at' => now()->toIso8601String()] + $status);
            Activity::log('import.failed', 'Import stopped: '.$exception->getMessage());

            return;
        }

        // Staff may have cancelled the import, or started another one, while this piece ran.
        $settings->flush();

        if (! $this->isCurrent(self::status())) {
            return;
        }

        $finished = $step >= count($steps);

        $settings->set('import.status', [
            'state' => $finished ? 'done' : 'running',
            'step' => $steps[$step] ?? null,
            'updated_at' => now()->toIso8601String(),
            'finished_at' => $finished ? now()->toIso8601String() : null,
        ] + $status);

        if ($finished) {
            Activity::log('import.finished', $importer::name().' import finished.');

            return;
        }

        static::dispatch($step, $afterId, $this->runId);
    }
}
