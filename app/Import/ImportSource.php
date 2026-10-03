<?php

namespace App\Import;

use App\Auth\LegacyPassword;
use App\Billing\RenewalGenerator;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Domain;
use App\Models\ImportMapping;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
use Pdo\Mysql;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * A billing system Nuvabill can import from, read straight from its database.
 *
 * Each step reads rows in ID order after a cursor, so a big import runs in small pieces. Every imported
 * record is kept in import_mappings, so running the import again updates what changed (statuses, due
 * dates, new invoices and replies) instead of adding copies. A row that cannot be imported is skipped
 * with its reason; it never stops the rest of the import.
 */
abstract class ImportSource
{
    public const CONNECTION = 'import_source';

    /**
     * The mapping source name, for example "whmcs".
     */
    abstract public static function key(): string;

    /**
     * The product name, for example "WHMCS".
     */
    abstract public static function name(): string;

    /**
     * Steps in the order they run, with their English labels. Later steps need the IDs from earlier ones.
     *
     * @return array<string, string>
     */
    abstract public static function steps(): array;

    /**
     * Where the key it asks for is found, in plain words, or null when it needs no key. With the key,
     * encrypted passwords can be read (see {@see self::$secret}).
     */
    public static function keyHelp(): ?string
    {
        return null;
    }

    /**
     * The key also checks client passwords at their first sign-in, so it is kept after the import
     * (in the "import.password_keys" setting).
     */
    public static function keyChecksPasswords(): bool
    {
        return false;
    }

    /**
     * The file that holds the source's database details, for example "configuration.php".
     */
    abstract public static function configFile(): string;

    /**
     * The table each step reads.
     *
     * @return array<string, string>
     */
    abstract protected function tables(): array;

    /**
     * Tables that must exist for this to be the right kind of database.
     *
     * @return list<string>
     */
    abstract protected function requiredTables(): array;

    /**
     * The version of the source system, or an empty string.
     */
    abstract public function version(): string;

    /**
     * What an import would do and the problems it would meet, without changing anything.
     */
    abstract public function preflight(): Preflight;

    /**
     * @var array<string, array<int, int|null>>
     */
    private array $mappings = [];

    /**
     * @var array<string, bool>
     */
    private array $tableCache = [];

    /**
     * @var array{created: int, updated: int, skipped: int}
     */
    protected array $counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];

    /**
     * Rows that failed in the current run: [source ID, reason].
     *
     * @var list<array{id: int, error: string}>
     */
    protected array $errors = [];

    /**
     * @var array<int, string>
     */
    private array $clientCurrencies = [];

    /**
     * The database is on another server and staff turned encryption off.
     */
    private bool $unencrypted = false;

    private ?bool $sameDatabase = null;

    private ?string $placeholderPassword = null;

    /**
     * @param  string|null  $secret  The source's own encryption key, when staff gave it.
     */
    public function __construct(
        protected string $connection = self::CONNECTION,
        protected ?string $secret = null,
    ) {}

    /**
     * Connect to the source's MySQL database. A database on another server is reached over TLS and its
     * certificate is checked, unless staff turn that off ("tls" false) for a server without TLS.
     *
     * @param  array{host?: string, port?: int|string|null, database?: string, username?: string, password?: string|null, key?: string|null, tls?: bool|string|null, ssl_ca?: string|null}  $credentials
     */
    public static function connect(array $credentials, string $connection = self::CONNECTION): static
    {
        if (blank($credentials['host'] ?? null) || blank($credentials['database'] ?? null) || blank($credentials['username'] ?? null)) {
            throw new InvalidArgumentException(__('Enter the database host, name and username.'));
        }

        config(['database.connections.'.$connection => [
            'driver' => 'mysql',
            'host' => $credentials['host'],
            'port' => (int) ($credentials['port'] ?? 0) ?: 3306,
            'database' => $credentials['database'],
            'username' => $credentials['username'],
            'password' => (string) ($credentials['password'] ?? ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => (string) ($credentials['prefix'] ?? ''),
            'strict' => false,
            'options' => [PDO::ATTR_TIMEOUT => 10] + self::tlsOptions($credentials),
        ]]);

        DB::purge($connection);

        $importer = new static($connection, filled($credentials['key'] ?? null) ? (string) $credentials['key'] : null);
        $importer->unencrypted = ! self::isLocalHost((string) $credentials['host']) && ! self::wantsTls($credentials);

        return $importer;
    }

    private static function isLocalHost(string $host): bool
    {
        $host = strtolower(trim($host, " []\t\n\r"));

        return $host === 'localhost' || $host === '::1' || str_starts_with($host, '127.');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    private static function wantsTls(array $credentials): bool
    {
        return ! array_key_exists('tls', $credentials) || $credentials['tls'] === null || filter_var($credentials['tls'], FILTER_VALIDATE_BOOL);
    }

    /**
     * PDO options that encrypt the connection to another server and check its certificate, against the CA
     * file staff gave or this server's own list of certificate authorities.
     *
     * @param  array<string, mixed>  $credentials
     * @return array<int, mixed>
     */
    private static function tlsOptions(array $credentials): array
    {
        if (self::isLocalHost((string) $credentials['host']) || ! self::wantsTls($credentials) || ! extension_loaded('pdo_mysql')) {
            return [];
        }

        if (filled($credentials['ssl_ca'] ?? null)) {
            $file = trim((string) $credentials['ssl_ca']);

            if (! is_file($file) || ! is_readable($file)) {
                throw new InvalidArgumentException(__('The CA file :path was not found on this server.', ['path' => $file]));
            }

            return [Mysql::ATTR_SSL_CA => $file, Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true];
        }

        $locations = function_exists('openssl_get_cert_locations') ? openssl_get_cert_locations() : [];

        foreach ([ini_get('openssl.cafile'), getenv((string) ($locations['default_cert_file_env'] ?? 'SSL_CERT_FILE')), $locations['default_cert_file'] ?? null] as $file) {
            if (is_string($file) && $file !== '' && is_file($file)) {
                return [Mysql::ATTR_SSL_CA => $file, Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true];
            }
        }

        foreach ([ini_get('openssl.capath'), getenv((string) ($locations['default_cert_dir_env'] ?? 'SSL_CERT_DIR')), $locations['default_cert_dir'] ?? null] as $directory) {
            if (is_string($directory) && $directory !== '' && is_dir($directory)) {
                return [Mysql::ATTR_SSL_CAPATH => $directory, Mysql::ATTR_SSL_VERIFY_SERVER_CERT => true];
            }
        }

        throw new InvalidArgumentException(__('This server has no list of certificate authorities to check the database server with. Enter the CA file of the database server.'));
    }

    /**
     * The version and how many rows each step will read. Throws when this is not the right database.
     *
     * @return array{version: string, counts: array<string, int>}
     */
    public function check(): array
    {
        try {
            foreach ($this->requiredTables() as $table) {
                if (! $this->hasTable($table)) {
                    throw new RuntimeException(__('This database has no :system tables (:tables).', ['system' => static::name(), 'tables' => implode(', ', $this->requiredTables())]));
                }
            }
        } catch (Throwable $exception) {
            if (! $this->unencrypted && preg_match('/\b(ssl|tls|certificate)\b/i', $exception->getMessage())) {
                throw new RuntimeException(__('The encrypted connection failed (:error). Enter the CA file of the database server, or turn off encryption if that server has no TLS.', ['error' => Str::limit($exception->getMessage(), 200)]), previous: $exception);
            }

            throw $exception;
        }

        $counts = [];

        foreach ($this->tables() as $step => $table) {
            $counts[$step] = $this->hasTable($table) ? $this->rows($step)->count() : 0;
        }

        return ['version' => $this->version(), 'counts' => $counts];
    }

    /**
     * Import up to $limit rows of one step, starting after the given source ID.
     *
     * @return array{last_id: int, done: bool, created: int, updated: int, skipped: int, errors: list<array{id: int, error: string}>}
     */
    public function run(string $step, int $afterId = 0, int $limit = 200): array
    {
        $table = $this->tables()[$step] ?? throw new InvalidArgumentException("Unknown import step [{$step}].");
        $this->counts = ['created' => 0, 'updated' => 0, 'skipped' => 0];
        $this->errors = [];

        if (! ($this->sameDatabase ??= $this->isSameDatabase())) {
            throw new RuntimeException(__('Nuvabill already holds records imported from another :system database. Importing a second one is not supported.', ['system' => static::name()]));
        }

        if (! $this->hasTable($table)) {
            return ['last_id' => $afterId, 'done' => true, 'errors' => []] + $this->counts;
        }

        $key = $this->keyColumn($step);
        $rows = $this->rows($step)->where($key, '>', $afterId)->orderBy($key)->limit($limit)->get();

        if ($rows->isNotEmpty()) {
            $this->importBatch($step, $rows);
        }

        return ['last_id' => (int) ($rows->last()->{$key} ?? $afterId), 'done' => $rows->count() < $limit, 'errors' => $this->errors] + $this->counts;
    }

    /**
     * The query a step reads its rows from. Sources can join or filter here.
     */
    protected function rows(string $step): Builder
    {
        return $this->db()->table($this->tables()[$step]);
    }

    protected function keyColumn(string $step): string
    {
        return 'id';
    }

    /**
     * The whole batch in one transaction. When it fails, each row is tried on its own, so one bad
     * row is skipped with its reason and the others still come across.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    private function importBatch(string $step, Collection $rows): void
    {
        $method = 'import'.Str::studly($step);
        $before = $this->counts;

        try {
            DB::transaction(fn () => $this->{$method}($rows));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->counts = $before;
            $this->forgetMappings();
        }

        foreach ($rows as $row) {
            $counts = $this->counts;

            try {
                DB::transaction(fn () => $this->{$method}(collect([$row])));
            } catch (Throwable $exception) {
                $this->counts = $counts;
                $this->counts['skipped']++;
                $this->forgetMappings();
                $this->errors[] = ['id' => (int) ($row->{$this->keyColumn($step)} ?? 0), 'error' => Str::limit($exception->getMessage(), 200)];
            }
        }
    }

    /**
     * Create or update the Nuvabill record for a source row. $createOnly values are set on the first import only,
     * so changes staff make in Nuvabill (slugs, module settings, emails) are kept when the import runs again.
     * Payments and renewals made in Nuvabill are kept too (see {@see self::keepLocalProgress()}).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $createOnly
     * @return TModel
     */
    protected function upsert(string $entity, int $sourceId, string $model, array $attributes, array $createOnly = []): Model
    {
        $localId = $this->localId($entity, $sourceId);
        $record = $localId !== null ? $model::query()->find($localId) : null;

        if ($record !== null) {
            $record->forceFill($this->keepLocalProgress($record, $attributes))->save();
            $this->counts['updated']++;

            return $record;
        }

        $record = (new $model)->forceFill($attributes + $createOnly);
        $record->save();

        $this->remember($entity, $sourceId, (int) $record->getKey());
        $this->counts['created']++;

        return $record;
    }

    /**
     * The old system never sees what happens in Nuvabill after the switch, so a later run must not undo it:
     * an invoice paid, cancelled or published here stays so, and due and expiry dates never move back. The
     * source can still move an invoice on (unpaid to paid or cancelled, paid to refunded).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function keepLocalProgress(Model $record, array $attributes): array
    {
        if ($record instanceof Invoice) {
            $source = InvoiceStatus::tryFrom((string) ($attributes['status'] ?? ''));
            $settled = match ($record->status) {
                InvoiceStatus::Paid => $source !== InvoiceStatus::Refunded,
                InvoiceStatus::Cancelled => ! in_array($source, [InvoiceStatus::Paid, InvoiceStatus::Refunded, InvoiceStatus::Cancelled], true),
                InvoiceStatus::Refunded => true,
                default => false,
            };

            if ($settled || ($source === InvoiceStatus::Draft && $record->status !== InvoiceStatus::Draft)) {
                return Arr::except($attributes, ['status', 'amount_paid', 'paid_at', 'payment_method', 'subtotal', 'tax', 'total']);
            }

            // Paid in part here: the amount paid never goes down.
            if (array_key_exists('amount_paid', $attributes) && $record->amount_paid > (int) $attributes['amount_paid']) {
                $attributes['amount_paid'] = min($record->amount_paid, (int) ($attributes['total'] ?? $record->total));
            }

            return $attributes;
        }

        if ($record instanceof Service || $record instanceof Domain) {
            foreach (['next_due_date', 'expires_at'] as $column) {
                if (array_key_exists($column, $attributes)) {
                    $attributes[$column] = $this->laterDate($attributes[$column], $record->getAttribute($column));
                }
            }
        }

        return $attributes;
    }

    /**
     * The later of the source's date and the one Nuvabill has, so renewing in Nuvabill is never undone.
     */
    protected function laterDate(mixed $source, mixed $local): mixed
    {
        if ($local === null) {
            return $source;
        }

        $local = CarbonImmutable::parse($local)->startOfDay();
        $date = filled($source) ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($source)->startOfDay(), null, false) : null;

        return $date === null || $date->lessThan($local) ? $local->toDateString() : $source;
    }

    protected function localId(string $entity, mixed $sourceId): ?int
    {
        $sourceId = (int) $sourceId;

        if ($sourceId <= 0) {
            return null;
        }

        if (! array_key_exists($sourceId, $this->mappings[$entity] ?? [])) {
            $this->mappings[$entity][$sourceId] = ImportMapping::query()
                ->where(['source' => static::key(), 'entity' => $entity, 'source_id' => $sourceId])
                ->value('local_id');
        }

        return $this->mappings[$entity][$sourceId];
    }

    protected function remember(string $entity, int $sourceId, int $localId): void
    {
        ImportMapping::query()->updateOrCreate(
            ['source' => static::key(), 'entity' => $entity, 'source_id' => $sourceId],
            ['local_id' => $localId],
        );

        $this->mappings[$entity][$sourceId] = $localId;
    }

    /**
     * How many records of an entity came from this source before.
     */
    protected function importedCount(string $entity): int
    {
        return ImportMapping::query()->where(['source' => static::key(), 'entity' => $entity])->count();
    }

    /**
     * The mapping entity each step fills, for the dry run's "new" and "already imported" numbers.
     *
     * @return array<string, string>
     */
    protected function entities(): array
    {
        return [];
    }

    /**
     * One line per step: rows found, and how many were imported before. Also stops a second database
     * of the same system, and warns when the connection to another server is not encrypted.
     */
    protected function addSteps(Preflight $report): void
    {
        $report->problem(Preflight::ERROR, $this->isSameDatabase() ? 0 : 1, 'Nuvabill already holds records imported from another :system database. Importing a second one is not supported.', ['system' => static::name()]);
        $report->problem(Preflight::WARNING, (int) $this->unencrypted, 'The database is on another server and the connection is not encrypted.');

        foreach (static::steps() as $step => $label) {
            $total = $this->hasTable($this->tables()[$step]) ? $this->rows($step)->count() : 0;
            $entity = $this->entities()[$step] ?? null;
            $existing = $entity !== null ? min($total, $this->importedCount($entity)) : 0;

            $report->step($step, $label, $total, $total - $existing, $existing);
        }
    }

    /**
     * Client emails that are not valid, or already used by a Nuvabill client that did not come from this
     * source: both are skipped. Also clients an earlier version linked by email, which are not changed.
     *
     * @param  Collection<int, mixed>  $emails
     */
    protected function checkEmails(Preflight $report, Collection $emails): void
    {
        $emails = $emails->map(fn (mixed $email): string => strtolower((string) $this->text($email)));
        $invalid = $emails->reject(fn (string $email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL));
        // A subquery, so the number of bound values stays the same however many clients came across.
        $imported = ImportMapping::query()->select('local_id')->where(['source' => static::key(), 'entity' => 'client']);
        $taken = collect();

        foreach ($emails->diff($invalid)->unique()->chunk(500) as $chunk) {
            $taken = $taken->merge(Client::query()->whereIn('email', $chunk->values())->whereNotIn('id', $imported)->pluck('email'));
        }

        $report->problem(Preflight::WARNING, $invalid->count(), 'Clients without a valid email address: :count. They are skipped.', examples: $invalid->map(fn (string $email): string => $email === '' ? '(empty)' : $email)->all());
        $report->problem(Preflight::WARNING, $taken->count(), 'Clients whose email is already used by a Nuvabill account: :count. They are skipped until you change the email of that account or delete it.', examples: $taken->all());
        $report->problem(Preflight::WARNING, $this->linkedClients()->count(), 'Clients an earlier import linked by email to a Nuvabill account: :count. They are not changed; check that each account belongs to the same person.', examples: Client::query()->whereIn('id', $this->linkedClients()->select('local_id'))->orderBy('id')->limit(5)->pluck('email')->all());
    }

    /**
     * Mappings of clients that an import before 0.6.12 linked by email to an account made in Nuvabill
     * (by sign-up, staff or the API), instead of creating them.
     *
     * @return EloquentBuilder<ImportMapping>
     */
    protected function linkedClients(): EloquentBuilder
    {
        return ImportMapping::query()
            ->where(['source' => static::key(), 'entity' => 'client'])
            ->where(fn (EloquentBuilder $query) => $query
                ->whereIn('source_id', ImportMapping::query()->select('source_id')->where(['source' => static::key(), 'entity' => 'client_link']))
                ->orWhereIn('local_id', ActivityLog::query()->select('subject_id')->where('subject_type', (new Client)->getMorphClass())->whereIn('action', ['client.registered', 'client.created'])));
    }

    /**
     * Whether the saved mappings came from this database. They only hold the source's IDs, so a second
     * database of the same system would update and add to the first one's clients by ID. The clients
     * imported first and last are compared by email and sign-up day, which do not change on a re-run.
     */
    protected function isSameDatabase(): bool
    {
        $sample = collect();

        foreach (['asc', 'desc'] as $direction) {
            foreach (ImportMapping::query()->where(['source' => static::key(), 'entity' => 'client'])->orderBy('source_id', $direction)->limit(15)->get(['source_id', 'local_id']) as $mapping) {
                $sample[(int) $mapping->source_id] = (int) $mapping->local_id;
            }
        }

        $local = $sample->isEmpty() ? collect() : Client::query()->whereKey($sample->values()->all())->get(['id', 'email', 'created_at'])->keyBy('id');
        $sample = $sample->filter(fn (int $localId): bool => $local->has($localId));

        if ($sample->isEmpty() || ($source = $this->sourceClients($sample->keys()->all())) === null) {
            return true;
        }

        $present = $sample->filter(fn (int $localId, int $sourceId): bool => isset($source[$sourceId]));
        $matched = $present->filter(function (int $localId, int $sourceId) use ($local, $source): bool {
            $client = $local->get($localId);

            return strtolower((string) $this->text($source[$sourceId]['email'])) === strtolower((string) $client->email)
                || substr(trim((string) $source[$sourceId]['created']), 0, 10) === $client->created_at?->toDateString();
        });

        return $present->isNotEmpty() && $matched->count() * 2 >= $present->count();
    }

    /**
     * The source's clients with these IDs: their email and when they were added. Null when the source
     * cannot tell, which skips the check in {@see self::isSameDatabase()}.
     *
     * @param  list<int>  $ids
     * @return array<int, array{email: mixed, created: mixed}>|null
     */
    protected function sourceClients(array $ids): ?array
    {
        return null;
    }

    /**
     * @param  Collection<int, stdClass>  $rows  Rows with id, email and created.
     * @return array<int, array{email: mixed, created: mixed}>
     */
    protected function keyClients(Collection $rows): array
    {
        return $rows->mapWithKeys(fn (stdClass $row): array => [(int) $row->id => ['email' => $row->email, 'created' => $row->created]])->all();
    }

    /**
     * Which client passwords keep working after the import.
     *
     * @param  Collection<int, mixed>  $hashes
     */
    protected function checkPasswords(Preflight $report, Collection $hashes): void
    {
        $older = 0;
        $unreadable = 0;

        foreach ($hashes as $hash) {
            $hash = (string) $hash;

            if (LegacyPassword::isBcrypt($hash)) {
                continue;
            }

            $this->legacyPassword($hash) !== null ? $older++ : $unreadable++;
        }

        $report->problem(Preflight::INFO, $older, 'Clients with an older :system password: :count. It works at their first sign-in and is then replaced.', ['system' => static::name()]);
        $report->problem(Preflight::WARNING, $unreadable, 'Clients with a password Nuvabill cannot read: :count. They choose a new one on the sign-in page.');
    }

    /**
     * A rolled-back transaction may have remembered IDs that no longer exist.
     */
    protected function forgetMappings(): void
    {
        $this->mappings = [];
        $this->clientCurrencies = [];
    }

    /**
     * The value, or the value with "-2", "-3"... when another record already uses it.
     *
     * @param  class-string<Model>  $model
     */
    protected function unique(string $model, string $column, string $value): string
    {
        $candidate = $value;
        $suffix = 2;

        while ($model::query()->where($column, $candidate)->exists()) {
            $candidate = $value.'-'.$suffix++;
        }

        return $candidate;
    }

    /**
     * Give a newly imported client their old password: a bcrypt hash works as it is; other kinds
     * Nuvabill knows are checked at their first sign-in and then replaced (see {@see LegacyPassword}).
     */
    protected function applyPassword(Client $client, ?string $hash): void
    {
        $hash = (string) $hash;

        if (LegacyPassword::isBcrypt($hash)) {
            DB::table('clients')->where('id', $client->id)->update(['password' => LegacyPassword::normalizeBcrypt($hash), 'legacy_password' => null]);
        } elseif (($legacy = $this->legacyPassword($hash)) !== null) {
            DB::table('clients')->where('id', $client->id)->update(['legacy_password' => $legacy]);
        }
    }

    /**
     * The stored form of a source's own password hash, or null when Nuvabill cannot check it.
     */
    protected function legacyPassword(string $hash): ?string
    {
        return null;
    }

    protected function clientCurrency(int $clientId): string
    {
        return $this->clientCurrencies[$clientId] ??= (string) (Client::query()->whereKey($clientId)->value('currency') ?? setting('billing.currency'));
    }

    /**
     * The client's email when the row should be imported. Null when it is skipped: no valid email, or the
     * email belongs to a Nuvabill account this import did not create. Nuvabill does not prove that a client
     * owns their email, so a source client is never joined to an account someone could sign up with; staff
     * sort those out by hand. Clients an earlier version linked that way are left as they are.
     *
     * @param  string|null  $currency  The client's currency in the source. A linked account in another currency
     *                                 would get the source's amounts unconverted, so its records stop coming across.
     */
    protected function clientEmailToImport(int $sourceId, mixed $email, ?string $currency = null): ?string
    {
        if ($this->isLinkedClient($sourceId)) {
            $this->holdOtherCurrency($sourceId, $currency);
            $this->counts['updated']++;

            return null;
        }

        $email = strtolower((string) $this->text($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->counts['skipped']++;

            return null;
        }

        if ($this->localId('client', $sourceId) === null && ($existing = Client::query()->where('email', $email)->value('id')) !== null) {
            $this->counts['skipped']++;
            $this->errors[] = ['id' => $sourceId, 'error' => __('The email :email is already used by Nuvabill client #:id. Change the email of that account or delete it, then run the import again.', ['email' => $email, 'id' => $existing])];

            return null;
        }

        return $email;
    }

    /**
     * A client an import before 0.6.12 linked by email to an account made in Nuvabill. Every run since then
     * would have overwritten that account's details and wallet; now it is never changed.
     */
    private function isLinkedClient(int $sourceId): bool
    {
        if ($this->localId('client_link', $sourceId) !== null) {
            return true;
        }

        if (($localId = $this->localId('client', $sourceId)) === null) {
            return false;
        }

        $madeInNuvabill = ActivityLog::query()
            ->where('subject_type', (new Client)->getMorphClass())
            ->where('subject_id', $localId)
            ->whereIn('action', ['client.registered', 'client.created'])
            ->exists();

        if ($madeInNuvabill) {
            $this->remember('client_link', $sourceId, $localId);
        }

        return $madeInNuvabill;
    }

    /**
     * A linked account in another currency than the source client: its services, domains and invoices
     * would keep the source's amounts under the account's currency. They are no longer imported (the
     * client is no longer found), and staff are told on every run.
     */
    private function holdOtherCurrency(int $sourceId, ?string $currency): void
    {
        $localId = $this->localId('client_link', $sourceId);
        $local = $localId !== null ? Client::query()->whereKey($localId)->value('currency') : null;

        if ($currency === null || $local === null || strtoupper($currency) === strtoupper((string) $local)) {
            return;
        }

        ImportMapping::query()->where(['source' => static::key(), 'entity' => 'client', 'source_id' => $sourceId])->delete();
        $this->mappings['client'][$sourceId] = null;
        $this->errors[] = ['id' => $sourceId, 'error' => __('This client uses :source, but the Nuvabill account #:id it was linked to uses :local. Its services, domains and invoices are not imported; add them by hand.', ['source' => strtoupper($currency), 'id' => $localId, 'local' => strtoupper((string) $local)])];
    }

    /**
     * Whether a support department should be imported. One with the name of a Nuvabill department is
     * linked to it instead, and the import never changes that department.
     */
    protected function departmentToImport(int $sourceId, string $name): bool
    {
        if ($this->localId('department_link', $sourceId) !== null) {
            $this->counts['updated']++;

            return false;
        }

        if ($this->localId('department', $sourceId) === null && ($existing = TicketDepartment::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id')) !== null) {
            $this->remember('department', $sourceId, (int) $existing);
            $this->remember('department_link', $sourceId, (int) $existing);
            $this->counts['updated']++;

            return false;
        }

        return true;
    }

    /**
     * The password of a new client or staff account until its own one is applied. Hashing a random value
     * for every row made a batch slower than a job may run, so one hash is shared per run. Nobody knows
     * the value it hides, so it cannot be used to sign in.
     */
    protected function placeholderPassword(): string
    {
        return $this->placeholderPassword ??= Hash::make(Str::random(40));
    }

    /**
     * A client's balance in the source, as wallet entries: the first run adds it, later runs add or
     * take only what changed in the source since.
     */
    protected function syncCredit(Client $client, int $credit, int $sourceId): void
    {
        $description = 'Balance from '.static::name();
        $entries = fn () => CreditTransaction::query()->where('client_id', $client->id)->where('description', $description);

        // Imported before 0.4.9, when the balance was copied without a wallet entry. Every change in Nuvabill
        // writes an entry, so only money that no entry explains came from the source.
        if (! $client->wasRecentlyCreated && ! $entries()->exists()) {
            $untracked = $client->credit - (int) CreditTransaction::query()->where('client_id', $client->id)->sum('amount');

            if ($untracked > 0) {
                CreditTransaction::create([
                    'client_id' => $client->id,
                    'amount' => min($untracked, $client->credit),
                    'balance' => $client->credit,
                    'currency' => $client->currency,
                    'description' => $description,
                ]);
            }
        }

        $difference = $credit - (int) $entries()->sum('amount');

        if ($difference === 0) {
            return;
        }

        try {
            app(Wallet::class)->change($client, $difference, $description);
        } catch (RuntimeException) {
            // The client already spent some of it in Nuvabill; the wallet never goes below zero.
            $this->errors[] = ['id' => $sourceId, 'error' => __('The credit of :email went down, but the Nuvabill wallet already used it. Check the wallet.', ['email' => $client->email])];
        }
    }

    /**
     * Nuvabill's name for a payment method: known gateways get their Nuvabill slug, others keep their own name.
     */
    protected function gatewaySlug(mixed $name): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $this->text($name)));

        return match (true) {
            $slug === '' => 'manual',
            str_contains($slug, 'paypal') => 'paypal',
            str_contains($slug, 'stripe') => 'stripe',
            in_array($slug, ['banktransfer', 'bank', 'mailin', 'wiretransfer', 'custom', 'offline'], true) => 'banktransfer',
            default => Str::limit($slug, 60, ''),
        };
    }

    /**
     * Payment method names that are not a Nuvabill gateway.
     *
     * @param  Collection<int, mixed>  $names
     */
    protected function checkGateways(Preflight $report, Collection $names): void
    {
        $known = app(ExtensionManager::class)->ofType(ExtensionManifest::TYPE_GATEWAY)->keys()->all();
        $unknown = $names->filter(fn (mixed $name): bool => filled($name))->unique()
            ->reject(fn (mixed $name): bool => in_array($this->gatewaySlug($name), $known, true));

        $report->problem(Preflight::INFO, $unknown->count(), 'Payment methods not in Nuvabill: :count. Old payments keep their name.', examples: $unknown->values()->all());
    }

    /**
     * @param  Collection<int, mixed>  $codes
     */
    protected function checkCurrencies(Preflight $report, Collection $codes): void
    {
        $known = array_merge([strtoupper((string) setting('billing.currency'))], array_keys((array) setting('currency.rates', [])));
        $other = $codes->map(fn (mixed $code): string => strtoupper(trim((string) $code)))->filter()->unique()->reject(fn (string $code): bool => in_array($code, $known, true));

        $report->problem(Preflight::INFO, $other->count(), 'Currencies not set up in Nuvabill: :count. Their clients and invoices keep them; add exchange rates in Settings.', examples: $other->values()->all());
    }

    /**
     * The Nuvabill server module for a source's module name, when that module is installed.
     *
     * @param  array<string, string>  $map  Source module name (lowercase) => Nuvabill module.
     */
    protected function serverModule(mixed $name, array $map): ?string
    {
        $module = $map[strtolower(trim((string) $name))] ?? null;

        return $module !== null && app(ExtensionManager::class)->serverModuleNames()->has($module) ? $module : null;
    }

    /**
     * An invoice line, linked to its service or domain. A renewal line knows its period, so paying it
     * renews the service and Nuvabill never invoices that period again. When the source does not keep
     * the period, an unpaid renewal is for the period that starts on the next due date.
     */
    protected function addInvoiceItem(Invoice $invoice, string $type, string $description, int $amount, ?Service $service = null, ?Domain $domain = null, ?CarbonImmutable $start = null, bool $renewal = false): InvoiceItem
    {
        if ($service === null && $domain === null) {
            $type = InvoiceItem::TYPE_MANUAL;
        }

        if ($start === null && $renewal && $invoice->status === InvoiceStatus::Unpaid && ($due = $service?->next_due_date ?? $domain?->next_due_date) !== null) {
            $start = CarbonImmutable::parse($due);
        }

        $end = match (true) {
            $start === null => null,
            $type === InvoiceItem::TYPE_SERVICE && $service?->billing_cycle->isRecurring() => $service->billing_cycle->advance($start)->subDay(),
            $domain !== null && in_array($type, InvoiceItem::DOMAIN_TYPES, true) => $start->addYears(max(1, (int) $domain->years))->subDay(),
            default => null,
        };

        $key = match (true) {
            $end === null || $invoice->status === InvoiceStatus::Cancelled => null,
            $type === InvoiceItem::TYPE_SERVICE => RenewalGenerator::billingKey('service', $service->id, $start),
            $type === InvoiceItem::TYPE_DOMAIN_RENEW => RenewalGenerator::billingKey('domain', $domain->id, $start),
            default => null,
        };

        // The source may hold two invoices for one period; only the first keeps the key.
        if ($key !== null && InvoiceItem::query()->where('billing_key', $key)->exists()) {
            $key = null;
        }

        return $invoice->items()->create([
            'service_id' => $service?->id,
            'domain_id' => $domain?->id,
            'type' => $type,
            'description' => Str::limit($this->text($description) ?? '-', 250),
            'amount' => $amount,
            'period_start' => $end !== null ? $start : null,
            'period_end' => $end,
            'billing_key' => $key,
        ]);
    }

    /**
     * An invoice cancelled in the source after an earlier run: its periods may be invoiced again.
     */
    protected function freeBillingKeys(Invoice $invoice): void
    {
        if ($invoice->status === InvoiceStatus::Cancelled) {
            $invoice->items()->whereNotNull('billing_key')->update(['billing_key' => null]);
        }
    }

    /**
     * A ticket message, with its author: ['client', id] or ['admin', id].
     *
     * @param  array{0: string, 1: int|null}  $author
     */
    protected function addTicketReply(Ticket $ticket, array $author, string $message, string $at, ?string $ip = null): TicketReply
    {
        $reply = (new TicketReply)->forceFill([
            'ticket_id' => $ticket->id,
            'author_type' => $author[0],
            'author_id' => $author[1],
            'message' => trim(strip_tags((string) $this->text($message))) ?: '-',
            'ip_address' => filter_var($ip, FILTER_VALIDATE_IP) ?: null,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $reply->save();

        return $reply;
    }

    /**
     * The support department with this name, made when Nuvabill has none.
     */
    protected function departmentNamed(?string $name): int
    {
        $name = $this->text($name) ?? 'Support';

        return (int) (TicketDepartment::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->value('id')
            ?? TicketDepartment::query()->create(['name' => $name, 'is_visible' => true])->id);
    }

    protected function automationAdvice(Preflight $report): void
    {
        $report->problem(Preflight::INFO, 1, 'Turn off automation in :system when you switch, and run the import one last time, so clients do not get two invoices.', ['system' => static::name()]);
    }

    /**
     * Text without HTML encoding and surrounding spaces, or null when empty.
     */
    protected function text(mixed $value): ?string
    {
        $value = trim(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $value === '' ? null : $value;
    }

    /**
     * A date or date-time, or null for empty and "0000-00-00" values.
     */
    protected function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' || str_starts_with($value, '0000-00-00') ? null : $value;
    }

    protected function money(mixed $value): int
    {
        return is_numeric($value) ? Money::toMinor((string) $value) : 0;
    }

    protected function hasTable(string $table): bool
    {
        return $this->tableCache[$table] ??= Schema::connection($this->connection)->hasTable($table);
    }

    protected function hasColumn(string $table, string $column): bool
    {
        return $this->tableCache[$table.'.'.$column] ??= $this->hasTable($table) && Schema::connection($this->connection)->hasColumn($table, $column);
    }

    protected function db(): ConnectionInterface
    {
        return DB::connection($this->connection);
    }
}
