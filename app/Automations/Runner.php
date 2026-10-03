<?php

namespace App\Automations;

use App\Automations\Steps\OnlyIf;
use App\Automations\Steps\Wait;
use App\Jobs\ContinueAutomationRun;
use App\Models\Automation;
use App\Models\AutomationRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts automation runs, does their steps, pauses them at a wait and goes on afterwards.
 *
 * Every run is claimed before it works, so two workers never do the same steps, and each
 * automation runs once per invoice, client, service or ticket and occasion.
 */
class Runner
{
    public const MAX_STEPS = 30;

    /**
     * A run still "running" after this many minutes without any progress was interrupted.
     */
    private const STALE_MINUTES = 30;

    public function __construct(private readonly Registry $registry) {}

    /**
     * Start a run when the automation's conditions are met. $occurrence tells apart occasions that
     * can happen more than once for the same subject, such as each reply to a ticket.
     */
    public function start(Automation $automation, Model $subject, string $occurrence = ''): ?AutomationRun
    {
        $trigger = $this->registry->trigger($automation->trigger);

        if ($trigger === null || $subject->getMorphClass() !== $trigger->subject) {
            return null;
        }

        $context = new Context($automation->trigger, $subject);

        if (! $this->registry->conditionsPass($context, $automation->conditions ?? [])) {
            return null;
        }

        try {
            $run = AutomationRun::query()->forceCreate([
                'automation_id' => $automation->id,
                'subject_type' => $context->subjectType(),
                'subject_id' => $subject->getKey(),
                'client_id' => $context->client()?->id,
                'status' => AutomationRun::QUEUED,
                'step' => 0,
                'steps_hash' => self::hashSteps($automation->steps ?? []),
                'dedupe_key' => self::dedupeKey($automation, $subject, $occurrence),
                'log' => [['step' => null, 'text' => __('Started: :trigger', ['trigger' => $trigger->describe($automation->trigger_days)]), 'at' => now()->toIso8601String()]],
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        ContinueAutomationRun::dispatch($run->id)->afterCommit();

        return $run;
    }

    /**
     * Do the next steps of a run, until it ends or reaches a wait.
     */
    public function continue(AutomationRun $run): AutomationRun
    {
        $claimed = AutomationRun::query()->whereKey($run->id)
            ->whereIn('status', [AutomationRun::QUEUED, AutomationRun::WAITING])
            ->where(fn ($query) => $query->whereNull('resume_at')->orWhere('resume_at', '<=', now()))
            ->update(['status' => AutomationRun::RUNNING, 'updated_at' => now()]);

        $run->refresh();

        if ($claimed !== 1) {
            return $run;
        }

        $automation = $run->automation;
        $subject = $run->subject;

        if ($automation === null || $subject === null) {
            return $this->finish($run, AutomationRun::STOPPED, __('Stopped: what this run was about no longer exists.'));
        }

        if (! $automation->is_active) {
            return $this->finish($run, AutomationRun::STOPPED, __('Stopped: the automation was switched off.'));
        }

        // A run only knows the number of its next step. After staff change the steps, that number
        // points at another step, so going on could repeat a step (credit twice) or skip one.
        if ($run->step > 0 && $run->steps_hash !== null && $run->steps_hash !== self::hashSteps($automation->steps ?? [])) {
            return $this->finish($run, AutomationRun::STOPPED, __('Stopped: the steps of the automation were changed after this run started.'));
        }

        $trigger = $this->registry->trigger($automation->trigger);

        if ($run->step > 0 && $trigger?->stillTrue !== null && ! ($trigger->stillTrue)($subject)) {
            return $this->finish($run, AutomationRun::STOPPED, __('Stopped: what started this run is no longer true, for example the invoice was paid.'));
        }

        $context = new Context($automation->trigger, $subject);
        $steps = array_values($automation->steps);
        Registry::$paused = true;

        try {
            for ($index = $run->step; $index < count($steps) && $index < self::MAX_STEPS; $index++) {
                $config = (array) ($steps[$index]['config'] ?? []);
                $step = $this->registry->step((string) ($steps[$index]['type'] ?? ''));

                if ($step === null) {
                    return $this->finish($run, AutomationRun::FAILED, __('Step :number no longer exists. An extension may have been removed.', ['number' => $index + 1]), $index);
                }

                if ($step instanceof Wait) {
                    $run->addLog($step->summary($config), $index);
                    $run->forceFill(['step' => $index + 1, 'status' => AutomationRun::WAITING, 'resume_at' => $step->until($config)])->save();

                    return $run;
                }

                $subject->refresh();

                if ($step instanceof OnlyIf && ! $step->passes($context, $config)) {
                    return $this->finish($run, AutomationRun::STOPPED, __('Stopped: it is no longer true that :condition', ['condition' => $step->describe($config)]), $index);
                }

                $run->addLog($step->run($context, $config), $index);
                $run->forceFill(['step' => $index + 1])->save();
            }

            return $this->finish($run, AutomationRun::DONE, __('Done.'));
        } catch (StepFailed $failure) {
            return $this->finish($run, AutomationRun::FAILED, $failure->getMessage(), $run->step);
        } catch (Throwable $exception) {
            report($exception);

            return $this->finish($run, AutomationRun::FAILED, __('Something went wrong: :error', ['error' => Str::limit($exception->getMessage(), 200)]), $run->step);
        } finally {
            Registry::$paused = false;
        }
    }

    /**
     * What the automation would do for this subject now, without doing anything.
     *
     * @return array{trigger: string, conditions: list<array{text: string, passed: bool}>, matches: bool, lines: list<string>}
     */
    public function preview(Automation $automation, Model $subject): array
    {
        $context = new Context($automation->trigger, $subject);
        $conditions = array_map(fn (array $condition): array => [
            'text' => $this->registry->describeCondition($condition),
            'passed' => $this->registry->conditionPasses($context, $condition),
        ], $automation->conditions ?? []);
        $lines = [];

        foreach (array_values($automation->steps) as $index => $step) {
            $type = $this->registry->step((string) ($step['type'] ?? ''));

            if ($type === null || ! $type->appliesTo($context->subjectType())) {
                $lines[] = __(':number. This step cannot run here.', ['number' => $index + 1]);

                continue;
            }

            try {
                $lines[] = ($index + 1).'. '.$type->preview($context, (array) ($step['config'] ?? []));
            } catch (Throwable $exception) {
                $lines[] = ($index + 1).'. '.__('Could not check this step: :error', ['error' => Str::limit($exception->getMessage(), 120)]);
            }
        }

        return [
            'trigger' => $this->registry->describeTrigger($automation),
            'conditions' => $conditions,
            'matches' => collect($conditions)->every(fn (array $condition): bool => $condition['passed']),
            'lines' => $lines,
        ];
    }

    /**
     * Go on with runs whose wait is over, and runs the queue did not start (for example when the
     * queue worker is not running).
     */
    public function resumeDue(int $limit = 200): int
    {
        // Runs whose worker stopped in the middle of a step (killed for taking too long, out of
        // memory, a restart) would show "Running" forever. Every finished step saves the run, so
        // a run untouched for this long is not being worked on any more.
        $this->failInterrupted(AutomationRun::query()->where('updated_at', '<=', now()->subMinutes(self::STALE_MINUTES))->orderBy('id')->limit($limit));

        $runs = AutomationRun::query()
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('status', AutomationRun::WAITING)->where('resume_at', '<=', now()))
                ->orWhere(fn ($query) => $query->where('status', AutomationRun::QUEUED)->where('created_at', '<=', now()->subMinutes(2))))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($runs as $run) {
            $this->continue($run);
        }

        return $runs->count();
    }

    /**
     * Check the timed triggers ("an invoice is 7 days overdue") once for the day. Looks back a week
     * at most, so a missed day is caught up without starting on very old invoices.
     */
    public function scan(CarbonImmutable $today): int
    {
        $started = 0;

        foreach (Automation::query()->where('is_active', true)->orderBy('id')->get() as $automation) {
            $trigger = $this->registry->trigger($automation->trigger);

            if ($trigger === null || ! $trigger->isTimed()) {
                continue;
            }

            $days = (int) $automation->trigger_days;

            // Every subject in the window, a page at a time. Subjects stay in the window for a few
            // days, so the ones started on an earlier day are found with one query and skipped.
            ($trigger->due)($today, $days)->chunkById(200, function (Collection $subjects) use ($automation, $trigger, $days, $today, &$started): void {
                $skip = $this->startedAlready($automation, $trigger, $subjects, $days, $today);

                foreach ($subjects as $subject) {
                    if (isset($skip[$subject->getKey()])) {
                        continue;
                    }

                    rescue(function () use ($automation, $trigger, $subject, $days, &$started): void {
                        if ($this->start($automation, $subject, $trigger->occasionFor($subject, $days)) !== null) {
                            $started++;
                        }
                    });
                }
            });
        }

        return $started;
    }

    /**
     * The subjects of this page that already have a run for this occasion, keyed by id. Runs
     * started before occasions were part of the key ("d30" without the date) count while they are
     * recent, so updating Nuvabill never starts a second run (and a second late fee) for them.
     *
     * @param  Collection<int, Model>  $subjects
     * @return array<int|string, true>
     */
    private function startedAlready(Automation $automation, Trigger $trigger, Collection $subjects, int $days, CarbonImmutable $today): array
    {
        $keys = [];
        $older = [];

        foreach ($subjects as $subject) {
            $keys[self::dedupeKey($automation, $subject, $trigger->occasionFor($subject, $days))] = $subject->getKey();
            $older[self::dedupeKey($automation, $subject, 'd'.$days)] = $subject->getKey();
        }

        $found = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where(fn (Builder $query) => $query->whereIn('dedupe_key', array_keys($keys))
                ->orWhere(fn (Builder $query) => $query->whereIn('dedupe_key', array_keys($older))->where('created_at', '>=', $today->subDays($days + 8))))
            ->pluck('dedupe_key');

        $skip = [];

        foreach ($found as $key) {
            $skip[$keys[$key] ?? $older[$key]] = true;
        }

        return $skip;
    }

    /**
     * One run per automation, subject and occasion: the database refuses a second run with this key.
     */
    private static function dedupeKey(Automation $automation, Model $subject, string $occurrence): string
    {
        $key = 'a'.$automation->id.':'.$subject->getMorphClass().':'.$subject->getKey().':'.($occurrence !== '' ? $occurrence : 'once');

        return strlen($key) > 191 ? 'a'.$automation->id.':'.hash('sha256', $key) : $key;
    }

    /**
     * The worker doing this run stopped in the middle of a step (the job took too long, or the
     * process ended). Mark the run failed rather than go on: the step may have half happened.
     */
    public function interrupted(int $runId): void
    {
        $this->failInterrupted(AutomationRun::query()->whereKey($runId));
    }

    /**
     * @param  Builder<AutomationRun>  $runs
     */
    private function failInterrupted(Builder $runs): void
    {
        foreach ($runs->where('status', AutomationRun::RUNNING)->get() as $run) {
            // Claimed in one update, so a run a worker saved in the meantime is left alone.
            $claimed = AutomationRun::query()->whereKey($run->id)
                ->where('status', AutomationRun::RUNNING)
                ->where('updated_at', $run->getRawOriginal('updated_at'))
                ->update(['status' => AutomationRun::FAILED]);

            if ($claimed === 1) {
                $this->finish($run, AutomationRun::FAILED, __('Interrupted while working on step :number: it took too long or the worker stopped. Check what that step did before you do it by hand.', ['number' => $run->step + 1]), $run->step);
            }
        }
    }

    /**
     * A fingerprint of an automation's steps, the same however the database orders JSON keys.
     * The update that added it gives runs already under way the fingerprint of their steps.
     *
     * @param  array<int|string, mixed>  $steps
     */
    public static function hashSteps(array $steps): string
    {
        $sorted = function (mixed $value) use (&$sorted): mixed {
            if (! is_array($value)) {
                return $value;
            }

            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map($sorted, $value);
        };

        return hash('sha256', (string) json_encode($sorted(array_values($steps))));
    }

    private function finish(AutomationRun $run, string $status, string $text, ?int $step = null): AutomationRun
    {
        $run->addLog($text, $step);
        $run->forceFill([
            'status' => $status,
            'error' => $status === AutomationRun::FAILED ? Str::limit($text, 490) : null,
            'resume_at' => null,
            'finished_at' => now(),
        ])->save();

        return $run;
    }
}
