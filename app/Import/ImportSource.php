<?php

namespace App\Import;

use App\Auth\LegacyPassword;
use App\Billing\RenewalGenerator;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
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
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PDO;
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
     * @param  string|null  $secret  The source's own encryption key, when staff gave it.
     */
    public function __construct(
        protected string $connection = self::CONNECTION,
        protected ?string $secret = null,
    ) {}

    /**
     * Connect to the source's MySQL database.
     *
     * @param  array{host?: string, port?: int|string|null, database?: string, username?: string, password?: string|null, key?: string|null}  $credentials
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
            'options' => [PDO::ATTR_TIMEOUT => 10],
        ]]);

        DB::purge($connection);

        return new static($connection, filled($credentials['key'] ?? null) ? (string) $credentials['key'] : null);
    }

    /**
     * The version and how many rows each step will read. Throws when this is not the right database.
     *
     * @return array{version: string, counts: array<string, int>}
     */
    public function check(): array
    {
        foreach ($this->requiredTables() as $table) {
            if (! $this->hasTable($table)) {
                throw new RuntimeException(__('This database has no :system tables (:tables).', ['system' => static::name(), 'tables' => implode(', ', $this->requiredTables())]));
            }
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
            $record->forceFill($attributes)->save();
            $this->counts['updated']++;

            return $record;
        }

        $record = (new $model)->forceFill($attributes + $createOnly);
        $record->save();

        $this->remember($entity, $sourceId, (int) $record->getKey());
        $this->counts['created']++;

        return $record;
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
     * One line per step: rows found, and how many were imported before.
     */
    protected function addSteps(Preflight $report): void
    {
        foreach (static::steps() as $step => $label) {
            $total = $this->hasTable($this->tables()[$step]) ? $this->rows($step)->count() : 0;
            $entity = $this->entities()[$step] ?? null;
            $existing = $entity !== null ? min($total, $this->importedCount($entity)) : 0;

            $report->step($step, $label, $total, $total - $existing, $existing);
        }
    }

    /**
     * Client emails that are not valid (skipped) or already used by a Nuvabill client that did not come
     * from this source (linked to it).
     *
     * @param  Collection<int, mixed>  $emails
     */
    protected function checkEmails(Preflight $report, Collection $emails): void
    {
        $emails = $emails->map(fn (mixed $email): string => strtolower((string) $this->text($email)));
        $invalid = $emails->reject(fn (string $email): bool => (bool) filter_var($email, FILTER_VALIDATE_EMAIL));
        $imported = ImportMapping::query()->where(['source' => static::key(), 'entity' => 'client'])->pluck('local_id')->all();
        $linked = collect();

        foreach ($emails->diff($invalid)->unique()->chunk(500) as $chunk) {
            $linked = $linked->merge(Client::query()->whereIn('email', $chunk->values())->whereNotIn('id', $imported)->pluck('email'));
        }

        $report->problem(Preflight::WARNING, $invalid->count(), 'Clients without a valid email address: :count. They are skipped.', examples: $invalid->map(fn (string $email): string => $email === '' ? '(empty)' : $email)->all());
        $report->problem(Preflight::INFO, $linked->count(), 'Clients that already have a Nuvabill account with the same email: :count. They are linked to it and not changed.', examples: $linked->all());
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
     * The client's email when the row should be imported. Null when it was skipped (no valid email) or
     * linked to the Nuvabill client that already uses the email.
     */
    protected function clientEmailToImport(int $sourceId, mixed $email): ?string
    {
        $email = strtolower((string) $this->text($email));

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->counts['skipped']++;

            return null;
        }

        if ($this->localId('client', $sourceId) === null && ($existing = Client::query()->where('email', $email)->first())) {
            $this->remember('client', $sourceId, $existing->id);
            $this->counts['updated']++;

            return null;
        }

        return $email;
    }

    /**
     * A client's balance in the source, as wallet entries: the first run adds it, later runs add or
     * take only what changed in the source since.
     */
    protected function syncCredit(Client $client, int $credit, int $sourceId): void
    {
        $description = 'Balance from '.static::name();
        $entries = fn () => CreditTransaction::query()->where('client_id', $client->id)->where('description', $description);

        // Imported before 0.4.9, when the balance was copied without a wallet entry: that balance came from the source.
        if (! $client->wasRecentlyCreated && $client->credit > 0 && ! $entries()->exists()) {
            CreditTransaction::create([
                'client_id' => $client->id,
                'amount' => $client->credit,
                'balance' => $client->credit,
                'currency' => $client->currency,
                'description' => $description,
            ]);
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
