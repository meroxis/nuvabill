<?php

namespace App\Health;

use App\Models\ActivityLog;
use App\Models\HealthRun;
use App\Support\Activity;
use App\Support\Settings;
use App\Support\SiteBackup;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Looks inside the database: its size and tables, space that can be freed, and old records that
 * can go. Optimizes tables (after a backup) and cleans up old records.
 *
 * Clean-up only touches logs, finished sessions, cache entries, failed background jobs, license
 * check history and site health history. Clients, invoices, payments, services and tickets are
 * never removed here.
 */
class DatabaseInspector
{
    /**
     * Tables smaller than this are not worth optimizing.
     */
    private const MIN_FREE_BYTES = 1_048_576;

    public function __construct(private readonly Settings $settings) {}

    public function connection(): Connection
    {
        return DB::connection();
    }

    public function isMysql(): bool
    {
        return in_array($this->connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    public function isSqlite(): bool
    {
        return $this->connection()->getDriverName() === 'sqlite';
    }

    /**
     * For example "MariaDB 10.11.6", "MySQL 8.4.2" or "SQLite 3.45.1".
     *
     * @return array{product: string, version: string}
     */
    public function server(): array
    {
        if ($this->isSqlite()) {
            return ['product' => 'SQLite', 'version' => (string) $this->connection()->selectOne('select sqlite_version() as v')->v];
        }

        if ($this->isMysql()) {
            $raw = (string) $this->connection()->selectOne('select version() as v')->v;
            preg_match('/^(\d+\.\d+(\.\d+)?)/', $raw, $match);

            return ['product' => stripos($raw, 'mariadb') !== false ? 'MariaDB' : 'MySQL', 'version' => $match[1] ?? $raw];
        }

        return ['product' => ucfirst($this->connection()->getDriverName()), 'version' => ''];
    }

    /**
     * Every table with its size, free space and rows, biggest first.
     *
     * @return list<array{name: string, size: int, free: int, rows: int, engine: string|null, collation: string|null}>
     */
    public function tables(): array
    {
        if ($this->isMysql()) {
            $rows = $this->connection()->select(
                'select TABLE_NAME as name, ENGINE as engine, TABLE_COLLATION as collation, TABLE_ROWS as row_count,
                    coalesce(DATA_LENGTH, 0) + coalesce(INDEX_LENGTH, 0) as size, coalesce(DATA_FREE, 0) as free
                 from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_TYPE = ?',
                ['BASE TABLE'],
            );

            $tables = array_map(fn (object $row): array => [
                'name' => (string) $row->name,
                'size' => (int) $row->size,
                'free' => (int) $row->free,
                'rows' => (int) $row->row_count,
                'engine' => $row->engine,
                'collation' => $row->collation,
            ], $rows);
        } else {
            $tables = array_map(fn (string $name): array => [
                'name' => $name,
                'size' => 0,
                'free' => 0,
                'rows' => (int) $this->connection()->table($name)->count(),
                'engine' => null,
                'collation' => null,
            ], $this->tableNames());
        }

        usort($tables, fn (array $a, array $b): int => [$b['size'], $b['rows']] <=> [$a['size'], $a['rows']]);

        return $tables;
    }

    /**
     * @return array{size: int, free: int}
     */
    public function totals(): array
    {
        if ($this->isSqlite()) {
            $pageSize = (int) $this->connection()->selectOne('pragma page_size')->page_size;

            return [
                'size' => $pageSize * (int) $this->connection()->selectOne('pragma page_count')->page_count,
                'free' => $pageSize * (int) $this->connection()->selectOne('pragma freelist_count')->freelist_count,
            ];
        }

        $tables = $this->tables();

        return ['size' => array_sum(array_column($tables, 'size')), 'free' => array_sum(array_column($tables, 'free'))];
    }

    /**
     * Tables with space worth freeing.
     *
     * @return list<array{name: string, size: int, free: int, rows: int, engine: string|null, collation: string|null}>
     */
    public function tablesToOptimize(): array
    {
        return array_values(array_filter($this->tables(), fn (array $table): bool => $table['free'] >= self::MIN_FREE_BYTES));
    }

    /**
     * Old records that clean-up would remove now, per kind.
     *
     * @return array<string, array{label: string, count: int, keep: string|null}>
     */
    public function oldRecords(): array
    {
        $found = [];

        foreach ($this->cleanupQueries() as $key => [$label, $keep, $query]) {
            try {
                $found[$key] = ['label' => $label, 'count' => (int) $query()->count(), 'keep' => $keep];
            } catch (Throwable) {
                // A table that is not there on this site.
            }
        }

        return $found;
    }

    /**
     * Remove the old records. Returns how many of each kind went.
     *
     * @return array<string, int>
     */
    public function cleanUp(): array
    {
        $removed = [];

        foreach ($this->cleanupQueries() as $key => [$label, $keep, $query]) {
            try {
                $removed[$key] = 0;
                $column = $key === 'cache' ? 'key' : 'id';

                // In batches, so a large log never locks the table for long.
                do {
                    $ids = $query()->limit(1000)->pluck($column);
                    $batch = $ids->isEmpty() ? 0 : $query()->whereIn($column, $ids->all())->delete();
                    $removed[$key] += $batch;
                } while ($batch > 0);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $total = array_sum($removed);

        if ($total > 0) {
            Activity::log('database.cleaned', "Removed {$total} old records: ".collect($removed)->filter()->map(fn (int $count, string $key): string => "{$key} {$count}")->implode(', '));
        }

        return $removed;
    }

    /**
     * Rebuild tables with free space and refresh the statistics the database uses to plan queries.
     * A backup of the database is made first.
     *
     * @return array{tables: int, freed: int, backup: string|null}
     */
    public function optimize(bool $backupFirst = true): array
    {
        $before = $this->totals()['free'];
        $backup = $backupFirst ? app(SiteBackup::class)->create(SiteBackup::TYPE_DATABASE) : null;
        $count = 0;

        if ($this->isMysql()) {
            foreach ($this->tablesToOptimize() as $table) {
                $this->connection()->statement('optimize table '.$this->connection()->getQueryGrammar()->wrapTable($table['name']));
                $count++;
            }

            foreach ($this->tableNames() as $name) {
                $this->connection()->statement('analyze table '.$this->connection()->getQueryGrammar()->wrapTable($name));
            }
        } elseif ($this->isSqlite()) {
            $this->connection()->statement('vacuum');
            $this->connection()->statement('analyze');
            $count = count($this->tableNames());
        }

        $freed = max(0, $before - $this->totals()['free']);
        $this->settings->set('database.last_optimized_at', now()->toIso8601String());
        Activity::log('database.optimized', "Optimized {$count} database tables");

        return ['tables' => $count, 'freed' => $freed, 'backup' => $backup];
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_values(array_map(fn (array $table): string => (string) $table['name'], Schema::getTables()));
    }

    /**
     * What clean-up removes, per kind: [label, how long it is kept, query of what is older].
     *
     * @return array<string, array{0: string, 1: string|null, 2: \Closure(): Builder|\Illuminate\Database\Eloquent\Builder}>
     */
    private function cleanupQueries(): array
    {
        $days = fn (string $key): int => max(7, (int) $this->settings->get($key));
        $keep = fn (int $count): string => trans_choice(':count day|:count days', $count, ['count' => $count]);
        $queries = [
            'activity' => ['Activity log', $keep($days('database.keep_activity_days')), fn () => ActivityLog::query()->where('created_at', '<', now()->subDays($days('database.keep_activity_days')))],
            'failed_jobs' => ['Failed background jobs', $keep($days('database.keep_jobs_days')), fn () => DB::table('failed_jobs')->where('failed_at', '<', now()->subDays($days('database.keep_jobs_days')))],
            'health' => ['Site health history', $keep($days('database.keep_health_days')), fn () => HealthRun::query()->where('created_at', '<', now()->subDays($days('database.keep_health_days')))->where('id', '<', (int) HealthRun::query()->max('id'))],
        ];

        if (Schema::hasTable('license_checks')) {
            $queries['license_checks'] = ['License check history', $keep($days('database.keep_license_checks_days')), fn () => DB::table('license_checks')->where('created_at', '<', now()->subDays($days('database.keep_license_checks_days')))];
        }

        if (config('session.driver') === 'database' && Schema::hasTable('sessions')) {
            $queries['sessions'] = ['Sign-in sessions that ended', null, fn () => DB::table('sessions')->where('last_activity', '<', now()->subMinutes((int) config('session.lifetime'))->getTimestamp())];
        }

        if (config('cache.default') === 'database' && Schema::hasTable('cache')) {
            $queries['cache'] = ['Expired cache entries', null, fn () => DB::table('cache')->where('expiration', '<', now()->getTimestamp())];
        }

        return $queries;
    }
}
