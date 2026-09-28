<?php

namespace App\Health;

use App\Health\Checks\CoreFileChecks;
use App\Health\Checks\DatabaseHealthChecks;
use App\Health\Checks\DatabaseSafetyChecks;
use App\Health\Checks\ExtensionChecks;
use App\Health\Checks\FileChecks;
use App\Health\Checks\OutsideChecks;
use App\Health\Checks\PaymentChecks;
use App\Health\Checks\SettingsChecks;
use App\Health\Checks\StaffChecks;
use App\Health\Checks\UpkeepChecks;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\HealthIgnore;
use App\Models\HealthRun;
use App\Support\Demo;
use App\Support\Locales;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * Site health: runs every check, keeps the result with a score per tab (security, database,
 * search engines) and emails staff who may fix security issues when something new and urgent
 * shows up. Runs every night, after updates and when staff press "Check now".
 */
class SiteHealth
{
    /**
     * @var list<class-string<CheckGroup>>
     */
    public const GROUPS = [
        StaffChecks::class,
        FileChecks::class,
        OutsideChecks::class,
        SettingsChecks::class,
        PaymentChecks::class,
        ExtensionChecks::class,
        CoreFileChecks::class,
        UpkeepChecks::class,
        DatabaseSafetyChecks::class,
        DatabaseHealthChecks::class,
    ];

    /**
     * @var list<CheckGroup>
     */
    private array $extra = [];

    /**
     * Add a group of checks, for example from an add-on. It shows up on its section's tab.
     */
    public function extend(CheckGroup $group): void
    {
        $this->extra[] = $group;
    }

    /**
     * @return list<CheckGroup>
     */
    public function groups(?string $section = null): array
    {
        $groups = [...array_map(fn (string $class): CheckGroup => app($class), self::GROUPS), ...$this->extra];

        return array_values(array_filter($groups, fn (CheckGroup $group): bool => $section === null || $group->section() === $section));
    }

    public function group(string $section, string $key): ?CheckGroup
    {
        foreach ($this->groups($section) as $group) {
            if ($group->key() === $key) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Check everything and keep the result.
     */
    public function run(string $trigger = 'manual'): HealthRun
    {
        @set_time_limit(300);
        $started = hrtime(true);
        $results = [];

        foreach ($this->groups() as $group) {
            array_push($results, ...$this->runGroup($group));
        }

        $previous = HealthRun::latestRun();
        $run = HealthRun::query()->create($this->summarise($this->applyIgnores($results)) + [
            'trigger' => $trigger,
            'duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
        ]);

        $this->notify($run, $previous);

        if (setting('health.check_requested')) {
            app(Settings::class)->set('health.check_requested', false);
        }

        return $run;
    }

    /**
     * Check one group again, for example right after a fix, and update the latest run with it.
     */
    public function refreshGroup(string $section, string $key): ?HealthRun
    {
        $run = HealthRun::latestRun();
        $group = $this->group($section, $key);

        if ($run === null || $group === null) {
            return $run;
        }

        $others = $run->checks()->reject(fn (CheckResult $check): bool => $check->section === $section && $check->group === $key)->all();
        $results = [...$others, ...$this->runGroup($group)];

        $run->update($this->summarise($this->applyIgnores($results)));

        return $run;
    }

    /**
     * Stop warning about a check, with the reason staff gave.
     */
    public function ignore(string $checkId, string $reason, ?Admin $admin): void
    {
        HealthIgnore::query()->updateOrCreate(['check_id' => $checkId], ['reason' => Str::limit($reason, 250, ''), 'admin_id' => $admin?->id]);
        $this->reapplyIgnores();
    }

    public function unignore(string $checkId): void
    {
        HealthIgnore::query()->where('check_id', $checkId)->delete();
        $this->reapplyIgnores();
    }

    /**
     * A score from 0 to 100 for one tab: how much of what was checked passed, where urgent checks
     * weigh more. Checks that could not run, and ignored ones, do not count.
     *
     * @param  iterable<CheckResult>  $results
     */
    public static function score(iterable $results, string $section): ?int
    {
        $total = 0;
        $passed = 0;

        foreach ($results as $result) {
            if ($result->section !== $section || $result->ignored || $result->status === Status::Skipped) {
                continue;
            }

            $total += $result->weight;
            $passed += $result->status === Status::Passed ? $result->weight : 0;
        }

        return $total === 0 ? null : (int) round($passed / $total * 100);
    }

    /**
     * @return list<CheckResult>
     */
    private function runGroup(CheckGroup $group): array
    {
        try {
            return $group->run();
        } catch (Throwable $exception) {
            report($exception);

            return [new CheckResult(
                id: $group->section().'.'.$group->key().'.error',
                section: $group->section(),
                group: $group->key(),
                title: 'This part could not be checked',
                status: Status::Skipped,
                summary: 'Something went wrong: :error',
                params: ['error' => Str::limit($exception->getMessage(), 160)],
            )];
        }
    }

    /**
     * @param  list<CheckResult>  $results
     * @return list<CheckResult>
     */
    private function applyIgnores(array $results): array
    {
        $ignored = HealthIgnore::query()->pluck('reason', 'check_id');

        return array_map(function (CheckResult $result) use ($ignored): CheckResult {
            $plain = new CheckResult(...[...get_object_vars($result), 'ignored' => false, 'ignoreReason' => null]);

            return $ignored->has($result->id) ? $plain->withIgnore((string) $ignored[$result->id]) : $plain;
        }, $results);
    }

    private function reapplyIgnores(): void
    {
        $run = HealthRun::latestRun();
        $run?->update($this->summarise($this->applyIgnores($run->checks()->all())));
    }

    /**
     * @param  list<CheckResult>  $results
     * @return array<string, mixed>
     */
    private function summarise(array $results): array
    {
        $open = collect($results)->reject(fn (CheckResult $result): bool => $result->ignored);

        return [
            'security_score' => self::score($results, CheckGroup::SECURITY),
            'database_score' => self::score($results, CheckGroup::DATABASE),
            'seo_score' => self::score($results, CheckGroup::SEO),
            'urgent_count' => $open->where('status', Status::Urgent)->count(),
            'warning_count' => $open->where('status', Status::Warning)->count(),
            'passed_count' => $open->where('status', Status::Passed)->count(),
            'results' => array_map(fn (CheckResult $result): array => $result->toArray(), $results),
        ];
    }

    /**
     * Email staff who may fix security issues about problems that were not there last time.
     */
    private function notify(HealthRun $run, ?HealthRun $previous): void
    {
        $urgent = (bool) setting('health.email_urgent');
        $warnings = (bool) setting('health.email_warnings');

        if (Demo::isEnabled() || (! $urgent && ! $warnings)) {
            return;
        }

        /** @var array<string, Status> $before */
        $before = $previous?->checks()->filter(fn (CheckResult $check): bool => $check->isProblem())->mapWithKeys(fn (CheckResult $check): array => [$check->id => $check->status])->all() ?? [];

        /** @var Collection<int, CheckResult> $new */
        $new = $run->checks()->filter(function (CheckResult $check) use ($before, $urgent, $warnings): bool {
            if (! $check->isProblem() || ($check->status === Status::Urgent && ! $urgent) || ($check->status === Status::Warning && ! $warnings)) {
                return false;
            }

            $was = $before[$check->id] ?? null;

            return $was === null || ($was === Status::Warning && $check->status === Status::Urgent);
        })->values();

        if ($new->isEmpty()) {
            return;
        }

        // Email templates are written in English, so the list in them is too.
        $issues = Locales::inEnglish(fn (): string => $new->map(fn (CheckResult $check): string => '- **'.$check->displayTitle().'**'.($check->summary !== '' ? ': '.$check->displaySummary() : ''))->implode("\n"));

        foreach (Admin::query()->with('role')->where('is_active', true)->get() as $admin) {
            if ($admin->hasPermission('security.manage')) {
                app(TemplateMailer::class)->sendTo('admin.security_alert', $admin->email, $admin->name, [
                    'staff' => ['name' => $admin->name],
                    'issues' => $issues,
                    'score' => $run->security_score ?? '—',
                    'admin_url' => route('admin.health.index'),
                ]);
            }
        }
    }
}
