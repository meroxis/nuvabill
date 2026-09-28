<?php

namespace App\Automations;

use App\Automations\Steps\OnlyIf;
use App\Automations\Steps\Wait;
use App\Jobs\ContinueAutomationRun;
use App\Models\Automation;
use App\Models\AutomationRun;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
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

        $key = 'a'.$automation->id.':'.$context->subjectType().':'.$subject->getKey().':'.($occurrence !== '' ? $occurrence : 'once');

        try {
            $run = AutomationRun::query()->create([
                'automation_id' => $automation->id,
                'subject_type' => $context->subjectType(),
                'subject_id' => $subject->getKey(),
                'client_id' => $context->client()?->id,
                'status' => AutomationRun::QUEUED,
                'step' => 0,
                'dedupe_key' => strlen($key) > 191 ? 'a'.$automation->id.':'.hash('sha256', $key) : $key,
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

            foreach (($trigger->due)($today, $days)->limit(500)->get() as $subject) {
                rescue(function () use ($automation, $subject, $days, &$started): void {
                    if ($this->start($automation, $subject, 'd'.$days) !== null) {
                        $started++;
                    }
                });
            }
        }

        return $started;
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
