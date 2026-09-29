<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\DatabaseInspector;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * How safe the database is: who can reach it, where it runs, whether it still gets security
 * fixes, whether secrets are stored encrypted, and whether it is whole and up to date.
 */
class DatabaseSafetyChecks extends CheckGroup
{
    /**
     * When database versions stop getting security fixes. Versions not listed that are older than
     * the newest listed one are treated as out of support.
     *
     * @var array<string, array<string, string>>
     */
    private const END_OF_LIFE = [
        'MariaDB' => ['10.5' => '2025-06-24', '10.6' => '2026-07-06', '10.11' => '2028-02-16', '11.4' => '2029-05-29', '11.8' => '2028-06-04'],
        'MySQL' => ['5.7' => '2023-10-31', '8.0' => '2026-04-30', '8.4' => '2032-04-30'],
    ];

    /**
     * Columns that hold secrets and must be stored encrypted: table => columns.
     *
     * @var array<string, list<string>>
     */
    private const ENCRYPTED_COLUMNS = [
        'admins' => ['two_factor_secret'],
        'clients' => ['two_factor_secret'],
        'servers' => ['password', 'api_token'],
        'services' => ['password'],
        'extensions' => ['settings'],
        'domains' => ['epp_code'],
        'marketplace_installs' => ['license_key'],
    ];

    public function __construct(private readonly DatabaseInspector $database) {}

    public function key(): string
    {
        return 'database-safety';
    }

    public function section(): string
    {
        return self::DATABASE;
    }

    public function title(): string
    {
        return 'Safety';
    }

    public function description(): string
    {
        return 'Who can reach the database, where it runs, and whether secrets in it are encrypted.';
    }

    public function icon(): string
    {
        return 'lock';
    }

    public function run(): array
    {
        return [
            $this->scope(),
            $this->user(),
            $this->location(),
            $this->version(),
            $this->encrypted(),
            $this->integrity(),
            $this->migrations(),
        ];
    }

    private function scope(): CheckResult
    {
        $check = $this->check('db.scope', 'Nuvabill\'s database user can only reach its own database');

        if (! $this->database->isMysql()) {
            return $check->skipped('SQLite has no database users.');
        }

        try {
            $grants = array_map(fn (object $row): string => (string) array_values((array) $row)[0], DB::select('show grants for current_user()'));
        } catch (Throwable) {
            return $check->skipped('The database did not say which rights this user has.');
        }

        $global = array_values(array_filter($grants, fn (string $grant): bool => (bool) preg_match('/ ON \*\.\* /i', $grant) && ! preg_match('/^GRANT USAGE ON/i', $grant)));

        if ($global === []) {
            return $check->passed();
        }

        return $check->warning('The database user has rights on every database on the server',
            advice: 'If someone breaks into Nuvabill, they reach your other databases too. Make a user in your hosting panel with rights on Nuvabill\'s database only, and put it in the .env file.',
            items: array_map(fn (string $grant): array => ['label' => Str::limit(preg_replace("/IDENTIFIED BY PASSWORD '[^']*'/i", '', $grant), 140), 'mono' => true, 'status' => 'warning'], $global),
        );
    }

    private function user(): CheckResult
    {
        $check = $this->check('db.user', 'The database user is not "root" and has a password', weight: 5);

        if (! $this->database->isMysql()) {
            return $check->skipped('SQLite has no database users.');
        }

        $connection = $this->connectionSettings();

        if (strtolower((string) ($connection['username'] ?? '')) === 'root') {
            return $check->urgent('Nuvabill uses the "root" user',
                advice: 'Root can change every database and user on the server. Make a user just for Nuvabill.',
            );
        }

        if (blank($connection['password'] ?? null)) {
            return $check->urgent('The database user has no password',
                advice: 'Anyone who can reach the database server can sign in as Nuvabill. Set a long password and put it in the .env file.',
            );
        }

        return $check->passed();
    }

    /**
     * The settings the database connection really uses: a DB_URL address is split into user, password
     * and host only when Laravel connects, so the raw config can look as if there were no password.
     *
     * @return array<string, mixed>
     */
    private function connectionSettings(): array
    {
        return (array) DB::connection()->getConfig();
    }

    private function location(): CheckResult
    {
        $check = $this->check('db.location', 'The database password never crosses the internet', weight: 1);

        if (! $this->database->isMysql()) {
            return $check->passed('SQLite: a file on this server');
        }

        $connection = $this->connectionSettings();
        $host = (string) ($connection['host'] ?? '');

        if (filled($connection['unix_socket'] ?? null) || in_array($host, ['localhost', '127.0.0.1', '::1'], true) || filled($connection['options'][\PDO::MYSQL_ATTR_SSL_CA] ?? null)) {
            return $check->passed(':host', ['host' => $host ?: 'socket']);
        }

        return $check->warning('The database runs on :host without an encrypted connection', ['host' => $host],
            advice: 'The password and every query travel over the network. That is fine inside a private network; otherwise turn on SSL for the connection (MYSQL_ATTR_SSL_CA).',
        );
    }

    private function version(): CheckResult
    {
        $check = $this->check('db.version', 'The database server still gets security fixes');
        ['product' => $product, 'version' => $version] = $this->database->server();
        $table = self::END_OF_LIFE[$product] ?? null;

        if ($table === null) {
            return $check->passed(':product :version', ['product' => $product, 'version' => $version]);
        }

        preg_match('/^(\d+\.\d+)/', $version, $match);
        $branch = $match[1] ?? '';
        $end = $table[$branch] ?? null;

        if ($end === null) {
            $newest = array_key_last($table);

            return version_compare($branch, (string) $newest, '<')
                ? $check->warning(':product :version is not a long-term version and no longer gets fixes', ['product' => $product, 'version' => $version], advice: 'Ask your host for a long-term version such as MariaDB 11.4 or MySQL 8.4.')
                : $check->passed(':product :version', ['product' => $product, 'version' => $version]);
        }

        $until = Carbon::parse($end);

        if ($until->isPast()) {
            return $check->warning(':product :version stopped getting security fixes on :date', ['product' => $product, 'version' => $version, 'date' => $until->translatedFormat('d M Y')],
                advice: 'Ask your host to move the database to a supported version such as MariaDB 11.4 or MySQL 8.4.',
            );
        }

        return $check->passed(':product :version, fixes until :date', ['product' => $product, 'version' => $version, 'date' => $until->translatedFormat('M Y')]);
    }

    /**
     * Secrets are saved as Laravel encrypted values, which always start with the same few letters.
     */
    private function encrypted(): CheckResult
    {
        $check = $this->check('db.encrypted', 'Passwords, payment keys and two-factor secrets are stored encrypted', weight: 5);
        $plain = [];

        foreach (self::ENCRYPTED_COLUMNS as $table => $columns) {
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }

                $count = DB::table($table)->whereNotNull($column)->where($column, '!=', '')->where($column, 'not like', 'eyJpdiI6%')->count();

                if ($count > 0) {
                    $plain[] = ['label' => "{$table}.{$column}", 'value' => trans_choice(':count row|:count rows', $count, ['count' => $count]), 'mono' => true, 'status' => 'urgent'];
                }
            }
        }

        $secretSettings = DB::table('settings')->whereIn('key', Settings::SECRET_KEYS)
            ->whereNotNull('value')->whereNotIn('value', ['null', '""', ''])
            ->where('value', 'not like', 'eyJpdiI6%')->where('value', 'not like', '"eyJpdiI6%')
            ->pluck('key');

        foreach ($secretSettings as $key) {
            $plain[] = ['label' => "settings: {$key}", 'mono' => true, 'status' => 'urgent'];
        }

        if ($plain === []) {
            return $check->passed();
        }

        return $check->urgent(':count places hold secrets in plain text', ['count' => count($plain)],
            advice: 'Someone with a copy of the database could read them. This usually comes from data copied in by hand. Save the affected servers, gateways or settings again in the admin area, which encrypts them.',
            items: $plain,
        );
    }

    private function integrity(): CheckResult
    {
        $check = $this->check('db.integrity', 'No damaged tables', weight: 5);

        try {
            if ($this->database->isSqlite()) {
                $result = (string) (DB::selectOne('pragma quick_check')->quick_check ?? '');

                return $result === 'ok' ? $check->passed('Integrity check') : $check->urgent('SQLite reports: :result', ['result' => Str::limit($result, 160)],
                    advice: 'Restore the database from your last backup, or ask your host for help.',
                );
            }

            if (! $this->database->isMysql()) {
                return $check->skipped('This database type is not checked.');
            }

            $damaged = [];

            foreach ($this->database->tables() as $table) {
                // Very large tables are left for "Check now" in quiet hours; they take long to read.
                if ($table['size'] > 512 * 1024 * 1024) {
                    continue;
                }

                foreach (DB::select('check table '.DB::getQueryGrammar()->wrapTable($table['name'])) as $row) {
                    $row = array_change_key_case((array) $row);

                    if (in_array(strtolower((string) ($row['msg_type'] ?? '')), ['error', 'corrupt'], true)) {
                        $damaged[$table['name']] = (string) ($row['msg_text'] ?? '');
                    }
                }
            }
        } catch (Throwable $exception) {
            return $check->skipped('The tables could not be checked: :error', ['error' => Str::limit($exception->getMessage(), 120)]);
        }

        if ($damaged === []) {
            return $check->passed('Integrity check');
        }

        return $check->urgent(':count tables are damaged', ['count' => count($damaged)],
            advice: 'Try "Repair table" in phpMyAdmin, or restore the database from your last backup.',
            items: array_map(fn (string $name, string $message): array => ['label' => $name, 'value' => $message, 'mono' => true, 'status' => 'urgent'], array_keys($damaged), $damaged),
        );
    }

    private function migrations(): CheckResult
    {
        $check = $this->check('db.migrations', 'The database matches this version of Nuvabill', weight: 5);
        $migrator = app('migrator');

        if (! $migrator->repositoryExists()) {
            return $check->urgent('The database has not been set up');
        }

        $files = $migrator->getMigrationFiles([database_path('migrations'), ...$migrator->paths()]);
        $pending = array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        if ($pending === []) {
            return $check->passed('No missing changes');
        }

        return $check->urgent(':count database changes of Nuvabill :version are missing', ['count' => count($pending), 'version' => config('nuvabill.version')],
            advice: 'An update did not finish. Parts of Nuvabill that need the new tables can fail until the changes are made.',
            items: array_map(fn (string $name): array => ['label' => $name, 'mono' => true, 'status' => 'urgent'], $pending),
            fix: $this->fix('db.migrate', 'Make the changes now', confirm: 'The missing database changes are made. A backup of the database is made first.'),
        );
    }
}
