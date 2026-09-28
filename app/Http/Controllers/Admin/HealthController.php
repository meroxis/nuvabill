<?php

namespace App\Http\Controllers\Admin;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\DatabaseInspector;
use App\Health\Repairs;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Http\Controllers\Controller;
use App\Models\HealthRun;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Seo\SeoText;
use App\Seo\SiteAddress;
use App\Seo\Sitemap;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;
use Illuminate\View\View;
use Throwable;

/**
 * Site health: the security, database and search engine tabs, a page per group of checks, the list of every
 * check, and the buttons that check again, fix, ignore, optimize and clean up.
 */
class HealthController extends Controller
{
    public function __construct(private readonly SiteHealth $health) {}

    public function index(Settings $settings): View
    {
        $run = HealthRun::latestRun();
        $checks = $run?->checks(CheckGroup::SECURITY) ?? collect();

        return view('admin.health.index', [
            'run' => $run,
            'tabs' => $this->tabs($run),
            'groups' => $this->groupCards(CheckGroup::SECURITY, $checks),
            'problems' => $this->problems($checks),
            'changes' => $this->changes($run, CheckGroup::SECURITY),
            'history' => $this->history(CheckGroup::SECURITY),
            'settings' => $settings->all(),
        ]);
    }

    public function database(DatabaseInspector $database, Settings $settings): View
    {
        $run = HealthRun::latestRun();
        $totals = $database->totals();

        return view('admin.health.database', [
            'run' => $run,
            'tabs' => $this->tabs($run),
            'groups' => $this->groupCards(CheckGroup::DATABASE, $run?->checks(CheckGroup::DATABASE) ?? collect()),
            'server' => $database->server(),
            'tableCount' => count($database->tableNames()),
            'totals' => $totals,
            'tables' => array_slice($database->tablesToOptimize(), 0, 12),
            'oldRecords' => $database->oldRecords(),
            'isSqlite' => $database->isSqlite(),
            'settings' => $settings->all(),
        ]);
    }

    public function seo(Sitemap $sitemap): View
    {
        $run = HealthRun::latestRun();
        $checks = $run?->checks(CheckGroup::SEO) ?? collect();

        return view('admin.health.seo', [
            'run' => $run,
            'tabs' => $this->tabs($run),
            'groups' => $this->groupCards(CheckGroup::SEO, $checks),
            'problems' => $this->problems($checks),
            'checked' => $checks->isNotEmpty(),
            'shown' => setting('seo.visible') ? count($sitemap->pages()) : 0,
            'hidden' => Product::query()->visible()->where('seo_hidden', true)->count() + ProductGroup::query()->visible()->where('seo_hidden', true)->count(),
            'home' => ['title' => SeoText::homeTitle(), 'description' => SeoText::homeDescription(), 'url' => SiteAddress::url('')],
        ]);
    }

    public function group(string $section, string $group): View
    {
        $definition = $this->health->group($section, $group) ?? abort(404);
        $run = HealthRun::latestRun();

        return view('admin.health.group', [
            'run' => $run,
            'section' => $section,
            'group' => $definition,
            'checks' => $this->sorted($run?->checks($section)->where('group', $group) ?? collect()),
        ]);
    }

    public function checks(Request $request, string $section): View
    {
        $run = HealthRun::latestRun();
        $all = $run?->checks($section) ?? collect();
        $filter = (string) $request->query('show', 'all');
        $shown = match ($filter) {
            'urgent' => $all->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Urgent),
            'warning' => $all->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Warning),
            'passed' => $all->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Passed),
            'ignored' => $all->filter(fn (CheckResult $check): bool => $check->ignored),
            default => $all,
        };

        return view('admin.health.checks', [
            'run' => $run,
            'section' => $section,
            'filter' => $filter,
            'counts' => [
                'all' => $all->count(),
                'urgent' => $all->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Urgent)->count(),
                'warning' => $all->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Warning)->count(),
                'passed' => $all->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Passed)->count(),
                'ignored' => $all->filter(fn (CheckResult $check): bool => $check->ignored)->count(),
            ],
            'groups' => collect($this->health->groups($section))->map(fn (CheckGroup $group): array => [
                'group' => $group,
                'checks' => $this->sorted($shown->where('group', $group->key())),
            ])->filter(fn (array $item): bool => $item['checks']->isNotEmpty())->values(),
        ]);
    }

    public function run(): RedirectResponse
    {
        try {
            $run = $this->health->run('manual');
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('The check could not finish: :error', ['error' => $exception->getMessage()]));
        }

        return back()->with('status', trans_choice('Checked. :count urgent issue.|Checked. :count urgent issues.', $run->urgent_count, ['count' => $run->urgent_count]));
    }

    public function fix(Request $request, Repairs $repairs): RedirectResponse
    {
        $data = $request->validate([
            'check' => ['required', 'string', 'max:80'],
            'item' => ['nullable', 'integer', 'min:0'],
        ]);

        $check = HealthRun::latestRun()?->check($data['check']) ?? abort(404);
        $fix = isset($data['item']) ? ($check->items[$data['item']]['fix'] ?? null) : $check->fix;

        if (! is_array($fix)) {
            return back()->with('error', __('This problem cannot be fixed automatically.'));
        }

        try {
            $message = $repairs->run($fix, $request->user('admin'));
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', $exception->getMessage());
        }

        $this->health->refreshGroup($check->section, $check->group);

        return back()->with('status', $message);
    }

    public function ignore(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'check' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:250'],
        ]);

        HealthRun::latestRun()?->check($data['check']) ?? abort(404);
        $this->health->ignore($data['check'], $data['reason'], $request->user('admin'));

        return back()->with('status', __('The check no longer counts. You can stop ignoring it at any time.'));
    }

    public function unignore(Request $request): RedirectResponse
    {
        $data = $request->validate(['check' => ['required', 'string', 'max:80']]);
        $this->health->unignore($data['check']);

        return back()->with('status', __('The check counts again.'));
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'nightly' => ['boolean'],
            'outside_check' => ['boolean'],
            'email_urgent' => ['boolean'],
            'email_warnings' => ['boolean'],
        ]);

        $settings->setMany([
            'health.nightly' => (bool) ($data['nightly'] ?? false),
            'health.outside_check' => (bool) ($data['outside_check'] ?? false),
            'health.email_urgent' => (bool) ($data['email_urgent'] ?? false),
            'health.email_warnings' => (bool) ($data['email_warnings'] ?? false),
        ]);

        return back()->with('status', __('Settings saved.'));
    }

    public function optimize(DatabaseInspector $database): RedirectResponse
    {
        try {
            $result = $database->optimize();
        } catch (Throwable $exception) {
            report($exception);

            return back()->with('error', __('The database could not be optimized: :error', ['error' => $exception->getMessage()]));
        }

        $this->health->refreshGroup(CheckGroup::DATABASE, 'database-health');

        return back()->with('status', __(':count tables were optimized and :size was freed. A backup of the database was made first.', [
            'count' => $result['tables'],
            'size' => Number::fileSize($result['freed']),
        ]));
    }

    public function cleanup(DatabaseInspector $database): RedirectResponse
    {
        $removed = array_sum($database->cleanUp());
        $this->health->refreshGroup(CheckGroup::DATABASE, 'database-health');

        return back()->with('status', trans_choice(':count old record was removed.|:count old records were removed.', $removed, ['count' => number_format($removed)]));
    }

    public function databaseSettings(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'cleanup_nightly' => ['boolean'],
            'optimize_weekly' => ['boolean'],
            'keep_activity_days' => ['required', 'integer', 'min:30', 'max:3650'],
            'keep_license_checks_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'keep_jobs_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'keep_health_days' => ['required', 'integer', 'min:7', 'max:3650'],
        ]);

        $settings->setMany([
            'database.cleanup_nightly' => (bool) ($data['cleanup_nightly'] ?? false),
            'database.optimize_weekly' => (bool) ($data['optimize_weekly'] ?? false),
            'database.keep_activity_days' => $data['keep_activity_days'],
            'database.keep_license_checks_days' => $data['keep_license_checks_days'],
            'database.keep_jobs_days' => $data['keep_jobs_days'],
            'database.keep_health_days' => $data['keep_health_days'],
        ]);

        return back()->with('status', __('Settings saved.'));
    }

    /**
     * The tabs with their score and open problems.
     *
     * @return list<array{section: string, label: string, route: string, score: int|null, urgent: int, warning: int}>
     */
    private function tabs(?HealthRun $run): array
    {
        return array_map(fn (array $tab): array => $tab + [
            'score' => $run?->score($tab['section']),
            'urgent' => $run?->problemCount($tab['section'], Status::Urgent) ?? 0,
            'warning' => $run?->problemCount($tab['section'], Status::Warning) ?? 0,
        ], [
            ['section' => CheckGroup::SECURITY, 'label' => __('Security'), 'route' => 'admin.health.index'],
            ['section' => CheckGroup::DATABASE, 'label' => __('Database'), 'route' => 'admin.health.database'],
            ['section' => CheckGroup::SEO, 'label' => __('Search engines'), 'route' => 'admin.health.seo'],
        ]);
    }

    /**
     * One card per group: its checks and how many passed.
     *
     * @param  Collection<int, CheckResult>  $checks
     * @return list<array{group: CheckGroup, checks: Collection<int, CheckResult>, passed: int, urgent: int, warning: int, ignored: int}>
     */
    private function groupCards(string $section, Collection $checks): array
    {
        return array_map(function (CheckGroup $group) use ($checks): array {
            $own = $checks->where('group', $group->key());

            return [
                'group' => $group,
                'checks' => $this->sorted($own),
                'passed' => $own->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status === Status::Passed)->count(),
                'counted' => $own->filter(fn (CheckResult $check): bool => ! $check->ignored && $check->status !== Status::Skipped)->count(),
                'urgent' => $own->filter(fn (CheckResult $check): bool => $check->isProblem() && $check->status === Status::Urgent)->count(),
                'warning' => $own->filter(fn (CheckResult $check): bool => $check->isProblem() && $check->status === Status::Warning)->count(),
                'ignored' => $own->filter(fn (CheckResult $check): bool => $check->ignored)->count(),
            ];
        }, $this->health->groups($section));
    }

    /**
     * Open problems, most important first.
     *
     * @param  Collection<int, CheckResult>  $checks
     * @return Collection<int, CheckResult>
     */
    private function problems(Collection $checks): Collection
    {
        return $checks->filter(fn (CheckResult $check): bool => $check->isProblem())
            ->sortBy([
                fn (CheckResult $a, CheckResult $b): int => ($a->status === Status::Urgent ? 0 : 1) <=> ($b->status === Status::Urgent ? 0 : 1),
                fn (CheckResult $a, CheckResult $b): int => $b->weight <=> $a->weight,
            ])
            ->values();
    }

    /**
     * Problems first, then passed, skipped and ignored checks.
     *
     * @param  Collection<int, CheckResult>  $checks
     * @return Collection<int, CheckResult>
     */
    private function sorted(Collection $checks): Collection
    {
        $order = fn (CheckResult $check): int => match (true) {
            $check->ignored => 4,
            $check->status === Status::Urgent => 0,
            $check->status === Status::Warning => 1,
            $check->status === Status::Passed => 2,
            default => 3,
        };

        return $checks->sortBy(fn (CheckResult $check): int => $order($check))->values();
    }

    /**
     * What is new and what was fixed since the run before.
     *
     * @return array{new: Collection<int, CheckResult>, fixed: Collection<int, CheckResult>, first: bool}
     */
    private function changes(?HealthRun $run, string $section): array
    {
        $previous = $run ? HealthRun::query()->where('id', '<', $run->id)->latest('id')->first() : null;

        if ($run === null || $previous === null) {
            return ['new' => collect(), 'fixed' => collect(), 'first' => $run !== null];
        }

        $before = $previous->checks($section)->keyBy('id');
        $now = $run->checks($section);

        return [
            'new' => $now->filter(fn (CheckResult $check): bool => $check->isProblem() && ! ($before[$check->id] ?? null)?->isProblem())->values(),
            'fixed' => $now->filter(fn (CheckResult $check): bool => ! $check->isProblem() && ($before[$check->id] ?? null)?->isProblem() === true)->values(),
            'first' => false,
        ];
    }

    /**
     * The score of each day in the last 30 days (the last run of each day).
     *
     * @return list<array{date: string, score: int}>
     */
    private function history(string $section): array
    {
        $column = $section.'_score';

        return HealthRun::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->whereNotNull($column)
            ->orderBy('id')
            ->get(['id', 'created_at', $column])
            ->groupBy(fn (HealthRun $run): string => $run->created_at->toDateString())
            ->map(fn (Collection $day): array => ['date' => $day->last()->created_at->translatedFormat('d M'), 'score' => (int) $day->last()->{$column}])
            ->values()
            ->all();
    }
}
