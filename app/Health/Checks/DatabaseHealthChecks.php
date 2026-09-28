<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\DatabaseInspector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * How healthy and tidy the database is: table types, character sets, keys, old records and
 * space that can be freed.
 */
class DatabaseHealthChecks extends CheckGroup
{
    /**
     * Old records worth a warning when nothing cleans them up.
     */
    private const OLD_RECORDS_LIMIT = 10_000;

    public function __construct(private readonly DatabaseInspector $database) {}

    public function key(): string
    {
        return 'database-health';
    }

    public function section(): string
    {
        return self::DATABASE;
    }

    public function title(): string
    {
        return 'Health';
    }

    public function description(): string
    {
        return 'Table types, character sets, keys, old records and space that can be freed.';
    }

    public function icon(): string
    {
        return 'server';
    }

    public function run(): array
    {
        $tables = $this->database->isMysql() ? $this->database->tables() : [];

        return [
            $this->engines($tables),
            $this->charsets($tables),
            $this->primaryKeys(),
            $this->oldRecords(),
            $this->freeSpace(),
        ];
    }

    /**
     * @param  list<array{name: string, size: int, free: int, rows: int, engine: string|null, collation: string|null}>  $tables
     */
    private function engines(array $tables): CheckResult
    {
        $check = $this->check('db.engine', 'All tables are crash-safe (InnoDB)');

        if (! $this->database->isMysql()) {
            return $check->skipped('Only MySQL and MariaDB have table types.');
        }

        $other = array_values(array_filter($tables, fn (array $table): bool => $table['engine'] !== null && strcasecmp($table['engine'], 'InnoDB') !== 0));

        if ($other === []) {
            return $check->passed();
        }

        return $check->warning(':count tables use another type', ['count' => count($other)],
            advice: 'Other table types (such as MyISAM) can lose data in a crash and lock the whole table on every change. Convert them with "ALTER TABLE name ENGINE=InnoDB", after a backup.',
            items: array_map(fn (array $table): array => ['label' => $table['name'], 'value' => (string) $table['engine'], 'mono' => true, 'status' => 'warning'], $other),
        );
    }

    /**
     * @param  list<array{name: string, size: int, free: int, rows: int, engine: string|null, collation: string|null}>  $tables
     */
    private function charsets(array $tables): CheckResult
    {
        $check = $this->check('db.charset', 'All tables store every language and emoji (utf8mb4)');

        if (! $this->database->isMysql()) {
            return $check->passed('SQLite stores every language');
        }

        $other = array_values(array_filter($tables, fn (array $table): bool => $table['collation'] !== null && ! str_starts_with(strtolower($table['collation']), 'utf8mb4')));

        if ($other === []) {
            return $check->passed();
        }

        return $check->warning(':count tables cannot store every character', ['count' => count($other)],
            advice: 'Names and ticket replies in some languages, and emoji, can be cut off or turned into question marks. Convert these tables to utf8mb4, after a backup.',
            items: array_map(fn (array $table): array => ['label' => $table['name'], 'value' => (string) $table['collation'], 'mono' => true, 'status' => 'warning'], $other),
        );
    }

    private function primaryKeys(): CheckResult
    {
        $check = $this->check('db.keys', 'Every table has a primary key', weight: 1);

        if (! $this->database->isMysql()) {
            return $check->skipped('Checked on MySQL and MariaDB.');
        }

        $missing = array_map(fn (object $row): string => (string) $row->name, DB::select(
            "select t.TABLE_NAME as name from information_schema.TABLES t
             where t.TABLE_SCHEMA = database() and t.TABLE_TYPE = 'BASE TABLE'
             and not exists (select 1 from information_schema.TABLE_CONSTRAINTS c
                 where c.TABLE_SCHEMA = t.TABLE_SCHEMA and c.TABLE_NAME = t.TABLE_NAME and c.CONSTRAINT_TYPE = 'PRIMARY KEY')",
        ));

        if ($missing === []) {
            return $check->passed();
        }

        return $check->warning(':count tables have no primary key', ['count' => count($missing)],
            advice: 'Tables without a key get slow as they grow, and some backup and copy tools skip them.',
            items: array_map(fn (string $name): array => ['label' => $name, 'mono' => true, 'status' => 'warning'], $missing),
        );
    }

    private function oldRecords(): CheckResult
    {
        $check = $this->check('db.old_records', 'Old records do not pile up', weight: 1);
        $old = $this->database->oldRecords();
        $total = array_sum(array_column($old, 'count'));

        if (setting('database.cleanup_nightly')) {
            return $check->passed('Cleaned up every night');
        }

        if ($total < self::OLD_RECORDS_LIMIT) {
            return $check->passed(':count old records', ['count' => number_format($total)]);
        }

        return $check->warning(':count old records and nothing cleans them up', ['count' => number_format($total)],
            advice: 'Old logs and sessions make backups bigger and some pages slower. Clean them up now, or let it happen every night.',
            items: collect($old)->filter(fn (array $kind): bool => $kind['count'] > 0)->map(fn (array $kind): array => ['label' => __($kind['label']), 'value' => number_format($kind['count'])])->values()->all(),
            fix: $this->fix('db.cleanup', 'Clean up now', confirm: 'Old logs, ended sessions and history are removed. Clients, invoices, payments, services and tickets are never touched.'),
        );
    }

    private function freeSpace(): CheckResult
    {
        $check = $this->check('db.free_space', 'Tables have little unused space', weight: 1);
        $totals = $this->database->totals();
        $free = $totals['free'];

        if ($free < 10 * 1024 * 1024 || $free < $totals['size'] * 0.2) {
            return $check->passed(':free unused', ['free' => Number::fileSize($free)]);
        }

        return $check->warning(':free could be freed (:percent% of the database)', ['free' => Number::fileSize($free), 'percent' => (int) round($free / max(1, $totals['size']) * 100)],
            advice: 'Deleted records leave empty space in the tables. Optimizing rebuilds them, which makes the database smaller and backups faster.',
            fix: $this->fix('db.optimize', 'Optimize now', confirm: 'A backup of the database is made first. Tables are rebuilt one by one; this can take a minute on a large database.'),
        );
    }
}
