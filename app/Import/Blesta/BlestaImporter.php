<?php

namespace App\Import\Blesta;

use App\Auth\LegacyPassword;
use App\Domains\DomainName;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProductType;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Import\ImportSource;
use App\Import\Preflight;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TldPrice;
use App\Models\Transaction;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use stdClass;

/**
 * Copies a Blesta installation into Nuvabill by reading its MySQL database.
 *
 * Blesta hashes client passwords with its system key (Blesta.system_key in config/blesta.php); with the
 * key they keep working. Services of registrar modules become domains. Values Blesta encrypts (server
 * passwords, API keys and account passwords) are not imported. Tickets come from the Support Manager plugin.
 */
class BlestaImporter extends ImportSource
{
    /**
     * @var array<string, string>
     */
    public const STEPS = [
        'staff' => 'Staff',
        'clients' => 'Clients',
        'product_groups' => 'Product groups',
        'servers' => 'Servers',
        'products' => 'Products and prices',
        'services' => 'Services',
        'tlds' => 'Domain prices',
        'domains' => 'Domains',
        'invoices' => 'Invoices',
        'transactions' => 'Payments',
        'departments' => 'Support departments',
        'tickets' => 'Tickets',
    ];

    private const TABLES = [
        'staff' => 'staff',
        'clients' => 'clients',
        'product_groups' => 'package_groups',
        'servers' => 'module_rows',
        'products' => 'packages',
        'services' => 'services',
        'tlds' => 'domains_tlds',
        'domains' => 'services',
        'invoices' => 'invoices',
        'transactions' => 'transactions',
        'departments' => 'support_departments',
        'tickets' => 'support_tickets',
    ];

    /**
     * Blesta module classes and the Nuvabill server module each becomes, when it is installed.
     */
    private const SERVER_MODULES = [
        'cpanel' => 'cpanel',
        'direct_admin' => 'directadmin',
        'directadmin' => 'directadmin',
        'plesk' => 'plesk',
        'virtualizor' => 'virtualizor',
        'proxmox' => 'proxmox',
        'cyberpanel' => 'cyberpanel',
        'hestiacp' => 'hestiacp',
        'solusvm' => 'solusvm',
        'virtfusion' => 'virtfusion',
    ];

    /**
     * Blesta registrar module classes; the ones Nuvabill has map to its registrar.
     */
    private const REGISTRAR_MODULES = [
        'logicboxes' => 'resellerclub',
        'namecheap' => 'namecheap',
        'enom' => 'enom',
        'opensrs' => 'opensrs',
        'generic_domains' => null,
        'internetbs' => null,
        'namesilo' => null,
        'openprovider' => null,
        'ovh_domains' => null,
        'realtime_register' => null,
        'nominet' => null,
        'connectreseller' => null,
    ];

    /**
     * Service field keys that hold the domain of a registrar service.
     */
    private const DOMAIN_FIELDS = ['domain', 'domain-name', 'domain_name'];

    /**
     * @var array<int, string>|null module ID => class
     */
    private ?array $modules = null;

    /**
     * @var array<int, stdClass>|null package_pricing ID => pricing with package_id
     */
    private ?array $pricings = null;

    /**
     * @var array<int, string>|null
     */
    private ?array $gateways = null;

    private ?string $defaultCurrency = null;

    /**
     * @var list<string>|null
     */
    private ?array $knownTlds = null;

    public static function key(): string
    {
        return 'blesta';
    }

    public static function name(): string
    {
        return 'Blesta';
    }

    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function configFile(): string
    {
        return 'config/blesta.php';
    }

    public static function keyHelp(): ?string
    {
        return 'The value of Blesta.system_key in config/blesta.php. With it, clients keep their passwords.';
    }

    public static function keyChecksPasswords(): bool
    {
        return true;
    }

    protected function tables(): array
    {
        return self::TABLES;
    }

    protected function requiredTables(): array
    {
        return ['clients', 'contacts', 'services', 'invoices'];
    }

    protected function entities(): array
    {
        return ['staff' => 'admin', 'clients' => 'client', 'product_groups' => 'product_group', 'servers' => 'server', 'products' => 'product', 'services' => 'service', 'domains' => 'domain', 'invoices' => 'invoice', 'transactions' => 'transaction', 'departments' => 'department', 'tickets' => 'ticket'];
    }

    public function version(): string
    {
        return $this->hasTable('settings') ? (string) $this->db()->table('settings')->where('key', 'database_version')->value('value') : '';
    }

    protected function rows(string $step): Builder
    {
        $query = parent::rows($step);
        $registrarModules = $this->moduleIds(array_keys(self::REGISTRAR_MODULES));
        $registrarPricings = fn (Builder $query) => $query->select('package_pricing.id')->from('package_pricing')
            ->join('packages', 'packages.id', '=', 'package_pricing.package_id')
            ->whereIn('packages.module_id', $registrarModules);

        return match ($step) {
            'servers' => $query->whereIn('module_id', $this->moduleIds(array_keys(self::SERVER_MODULES))),
            'products' => $query->where(fn (Builder $query) => $query->whereNull('module_id')->orWhereNotIn('module_id', $registrarModules)),
            'services' => $query->whereNotIn('pricing_id', $registrarPricings),
            'domains' => $query->whereIn('pricing_id', $registrarPricings),
            'transactions' => $query->where('status', 'approved'),
            'tickets' => $query->where('status', '!=', 'trash'),
            default => $query,
        };
    }

    /**
     * Blesta keeps bcrypt hashes of an HMAC made with its system key; those are checked at the first sign-in.
     */
    protected function applyPassword(Client $client, ?string $hash): void
    {
        $hash = (string) $hash;

        if (LegacyPassword::isBcrypt($hash)) {
            DB::table('clients')->where('id', $client->id)->update(['legacy_password' => 'hmac-bcrypt:'.self::key().':'.$hash]);
        }
    }

    /**
     * Staff become switched-off accounts, so ticket replies keep their author.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importStaff(Collection $rows): void
    {
        foreach ($rows as $row) {
            $email = strtolower((string) $this->text($row->email ?? null));

            if ($this->localId('admin', $row->id) !== null || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->counts['skipped']++;

                continue;
            }

            if ($existing = Admin::query()->where('email', $email)->first()) {
                $this->remember('admin', (int) $row->id, $existing->id);
                $this->counts['updated']++;

                continue;
            }

            $this->upsert('admin', (int) $row->id, Admin::class, [], [
                'name' => trim($this->text($row->first_name ?? null).' '.$this->text($row->last_name ?? null)) ?: $email,
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'is_active' => false,
            ]);
        }
    }

    /**
     * Clients with their primary contact, phone number, password and unused credit.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importClients(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $contacts = $this->db()->table('contacts')->whereIn('client_id', $ids)->where('contact_type', 'primary')->get()->keyBy('client_id');
        $phones = $this->hasTable('contact_numbers')
            ? $this->db()->table('contact_numbers')->whereIn('contact_id', $contacts->pluck('id'))->where('type', 'phone')->orderBy('id')->get()->groupBy('contact_id')
            : collect();
        $passwords = $this->hasTable('users') ? $this->db()->table('users')->whereIn('id', $rows->pluck('user_id')->filter())->pluck('password', 'id') : collect();
        $settings = $this->clientSettings($ids);

        foreach ($rows as $row) {
            $contact = $contacts->get($row->id);

            if ($contact === null || ($email = $this->clientEmailToImport((int) $row->id, $contact->email ?? null, $this->currencyCode($settings[$row->id]['default_currency'] ?? null))) === null) {
                if ($contact === null) {
                    $this->counts['skipped']++;
                }

                continue;
            }

            $client = $this->upsert('client', (int) $row->id, Client::class, [
                'first_name' => $this->text($contact->first_name ?? null) ?? '',
                'last_name' => $this->text($contact->last_name ?? null) ?? '',
                'company_name' => $this->text($contact->company ?? null),
                'phone' => $this->text($phones->get($contact->id)?->first()?->number ?? null),
                'address_1' => $this->text($contact->address1 ?? null),
                'address_2' => $this->text($contact->address2 ?? null),
                'city' => $this->text($contact->city ?? null),
                'state' => $this->text($contact->state ?? null),
                'postcode' => Str::limit((string) $this->text($contact->zip ?? null), 20, '') ?: null,
                'country' => preg_match('/^[A-Za-z]{2}$/', (string) ($contact->country ?? '')) ? strtoupper($contact->country) : null,
                'status' => (match ((string) ($row->status ?? '')) {
                    'inactive' => ClientStatus::Inactive,
                    'fraud' => ClientStatus::Closed,
                    default => ClientStatus::Active,
                })->value,
                'tax_id' => $this->text($settings[$row->id]['tax_id'] ?? null),
                'tax_exempt' => in_array((string) ($settings[$row->id]['tax_exempt'] ?? ''), ['true', '1'], true),
            ], [
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'currency' => $this->currencyCode($settings[$row->id]['default_currency'] ?? null),
                'created_at' => $this->date($contact->date_added ?? null) ?? now(),
            ]);

            if ($client->wasRecentlyCreated) {
                $this->applyPassword($client, $passwords->get($row->user_id ?? 0));
            }

            $this->syncCredit($client, $this->credit((int) $row->id, $client->currency), (int) $row->id);
        }
    }

    /**
     * Money a client paid that is not applied to an invoice yet, which Blesta shows as credit.
     */
    private function credit(int $clientId, string $currency): int
    {
        if (! $this->hasTable('transactions')) {
            return 0;
        }

        $paid = $this->db()->table('transactions')->where('client_id', $clientId)->where('status', 'approved')->where('currency', $currency)->pluck('amount')->sum(fn (mixed $amount): int => $this->money($amount));
        $applied = $this->hasTable('transaction_applied')
            ? $this->db()->table('transaction_applied')->join('transactions', 'transactions.id', '=', 'transaction_applied.transaction_id')
                ->where('transactions.client_id', $clientId)->where('transactions.status', 'approved')->where('transactions.currency', $currency)
                ->pluck('transaction_applied.amount')->sum(fn (mixed $amount): int => $this->money($amount))
            : 0;

        return max(0, (int) ($paid - $applied));
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProductGroups(Collection $rows): void
    {
        $names = $this->hasTable('package_group_names') ? $this->db()->table('package_group_names')->whereIn('package_group_id', $rows->pluck('id'))->orderBy('lang')->pluck('name', 'package_group_id') : collect();

        foreach ($rows as $row) {
            $name = $this->text($names->get($row->id) ?? $row->name ?? null) ?? 'Group '.$row->id;

            $this->upsert('product_group', (int) $row->id, ProductGroup::class, [
                'name' => $name,
            ], ['slug' => $this->unique(ProductGroup::class, 'slug', Str::slug($name) ?: 'group'), 'is_visible' => true]);
        }
    }

    /**
     * Servers are imported switched off: Blesta encrypts their passwords and keys, so staff enter them again.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServers(Collection $rows): void
    {
        $meta = $this->db()->table('module_row_meta')->whereIn('module_row_id', $rows->pluck('id'))->get()->groupBy('module_row_id');

        foreach ($rows as $row) {
            $module = $this->serverModule($this->modules()[(int) $row->module_id] ?? null, self::SERVER_MODULES);

            if ($module === null) {
                $this->counts['skipped']++;

                continue;
            }

            $values = $this->metaValues($meta->get($row->id, collect()));
            $hostname = $this->text($values['host_name'] ?? $values['hostname'] ?? $values['host'] ?? $values['ip_address'] ?? null) ?? 'localhost';
            $nameservers = $values['name_servers'] ?? [];

            $this->upsert('server', (int) $row->id, Server::class, [
                'name' => $this->text($values['server_name'] ?? $values['name'] ?? null) ?? $hostname,
                'module' => $module,
                'hostname' => $hostname,
                'ip_address' => filter_var($values['ip_address'] ?? null, FILTER_VALIDATE_IP) ?: (filter_var($hostname, FILTER_VALIDATE_IP) ?: null),
                'port' => (int) ($values['port'] ?? 0) ?: null,
                'use_ssl' => ! in_array(strtolower((string) ($values['use_ssl'] ?? 'true')), ['false', '0', ''], true),
                'username' => $this->text($values['user_name'] ?? $values['username'] ?? $values['user'] ?? null),
                'nameservers' => array_values(array_filter(array_map(fn (mixed $name): ?string => $this->text($name), is_array($nameservers) ? $nameservers : [$nameservers]))),
                'max_accounts' => (int) ($values['account_limit'] ?? 0) ?: null,
            ], ['is_active' => false]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProducts(Collection $rows): void
    {
        $ids = $rows->pluck('id');
        $names = $this->hasTable('package_names') ? $this->db()->table('package_names')->whereIn('package_id', $ids)->orderBy('lang')->pluck('name', 'package_id') : collect();
        $descriptions = $this->hasTable('package_descriptions') ? $this->db()->table('package_descriptions')->whereIn('package_id', $ids)->orderBy('lang')->pluck('text', 'package_id') : collect();
        $groups = $this->hasTable('package_group') ? $this->db()->table('package_group')->whereIn('package_id', $ids)->orderByDesc('package_group_id')->pluck('package_group_id', 'package_id') : collect();
        $meta = $this->hasTable('package_meta') ? $this->db()->table('package_meta')->whereIn('package_id', $ids)->get()->groupBy('package_id') : collect();
        $prices = $this->db()->table('package_pricing')->join('pricings', 'pricings.id', '=', 'package_pricing.pricing_id')
            ->whereIn('package_pricing.package_id', $ids)->select('pricings.*', 'package_pricing.package_id')->get()->groupBy('package_id');

        foreach ($rows as $row) {
            $groupId = $this->localId('product_group', $groups->get($row->id) ?? 0);

            if ($groupId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $module = $this->serverModule($this->modules()[(int) ($row->module_id ?? 0)] ?? null, self::SERVER_MODULES);
            $values = $this->metaValues($meta->get($row->id, collect()));
            $name = $this->text($names->get($row->id) ?? $row->name ?? null) ?? 'Product '.$row->id;
            $plan = (string) $this->text($values['package'] ?? $values['plan'] ?? null);

            $product = $this->upsert('product', (int) $row->id, Product::class, [
                'product_group_id' => $groupId,
                'name' => $name,
                'description' => $this->text($descriptions->get($row->id) ?? $row->description ?? null),
                'is_visible' => ($row->status ?? 'active') === 'active',
                'server_module' => $module,
                'stock' => is_numeric($row->qty ?? null) ? max(0, (int) $row->qty) : null,
            ], [
                'type' => ($module !== null ? ProductType::Hosting : ProductType::Other)->value,
                'slug' => $this->unique(Product::class, 'slug', Str::slug($name) ?: 'product'),
                'requires_domain' => $module !== null,
                'auto_setup' => AutoSetup::OnPayment->value,
                'module_config' => match ($module) {
                    'cpanel', 'directadmin' => ['package' => $plan],
                    'plesk' => ['plan' => $plan],
                    default => [],
                },
            ]);

            foreach ($prices->get($row->id, collect()) as $price) {
                if (($cycle = $this->cycle($price)) === null) {
                    continue;
                }

                ProductPrice::query()->updateOrCreate(
                    ['product_id' => $product->id, 'currency' => strtoupper((string) $price->currency), 'billing_cycle' => $cycle->value],
                    ['price' => max(0, $this->money($price->price ?? 0)), 'setup_fee' => max(0, $this->money($price->setup_fee ?? 0))],
                );
            }
        }
    }

    /**
     * The billing cycle of a Blesta pricing, or null for days and weeks, which Nuvabill does not bill.
     */
    private function cycle(?stdClass $pricing): ?BillingCycle
    {
        if ($pricing === null) {
            return null;
        }

        $term = (int) ($pricing->term ?? 0);
        $months = match ((string) ($pricing->period ?? '')) {
            'onetime' => -1,
            'month' => $term,
            'year' => $term * 12,
            default => 0,
        };

        return match ($months) {
            -1 => (float) ($pricing->price ?? 0) > 0 ? BillingCycle::OneTime : BillingCycle::Free,
            1 => BillingCycle::Monthly,
            3 => BillingCycle::Quarterly,
            6 => BillingCycle::SemiAnnually,
            12 => BillingCycle::Annually,
            24 => BillingCycle::Biennially,
            36 => BillingCycle::Triennially,
            default => null,
        };
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServices(Collection $rows): void
    {
        $fields = $this->serviceFields($rows->pluck('id')->all());
        $packages = $this->db()->table('packages')->whereIn('id', $rows->map(fn (stdClass $row): ?int => $this->pricing($row->pricing_id)?->package_id)->filter())->pluck('module_id', 'id');

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);
            $pricing = $this->pricing($row->pricing_id ?? 0);
            $productId = $this->localId('product', $pricing->package_id ?? 0);

            if ($clientId === null || $productId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $cycle = $this->cycle($pricing) ?? BillingCycle::OneTime;
            $status = $this->serviceStatus($row->status ?? null);
            $values = $fields[$row->id] ?? [];
            $class = $this->modules()[(int) ($packages[$pricing->package_id] ?? 0)] ?? '';
            $amount = $this->money($row->override_price ?? null ?: ($pricing->price_renews ?? null ?: $pricing->price ?? 0)) * max(1, (int) ($row->qty ?? 1));

            $this->upsert('service', (int) $row->id, Service::class, [
                'product_id' => $productId,
                'server_id' => $this->localId('server', $row->module_row_id ?? 0),
                'domain' => $this->text($values[$class.'_domain'] ?? $values['domain'] ?? null),
                'username' => $this->text($values[$class.'_username'] ?? $values['username'] ?? null),
                'status' => $status->value,
                'billing_cycle' => $cycle->value,
                'first_payment_amount' => $amount,
                'recurring_amount' => $cycle->isRecurring() ? $amount : 0,
                'registration_date' => $this->date($row->date_added ?? null) ?? today()->toDateString(),
                'next_due_date' => $cycle->isRecurring() ? $this->date($row->date_renews ?? null) : null,
                'suspended_at' => $status === ServiceStatus::Suspended ? ($this->date($row->date_suspended ?? null) ?? now()) : null,
            ], [
                'client_id' => $clientId,
                'currency' => $this->currencyCode($row->override_currency ?? null ?: ($pricing->currency ?? null), $this->clientCurrency($clientId)),
            ]);
        }
    }

    private function serviceStatus(mixed $value): ServiceStatus
    {
        return match ((string) $value) {
            'active' => ServiceStatus::Active,
            'suspended' => ServiceStatus::Suspended,
            'canceled', 'cancelled' => ServiceStatus::Cancelled,
            default => ServiceStatus::Pending,
        };
    }

    /**
     * Domain prices from the Domain Manager plugin: its one-year prices per currency.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTlds(Collection $rows): void
    {
        $prices = $this->db()->table('package_pricing')->join('pricings', 'pricings.id', '=', 'package_pricing.pricing_id')
            ->whereIn('package_pricing.package_id', $rows->pluck('package_id'))->where('pricings.period', 'year')->where('pricings.term', 1)
            ->select('pricings.*', 'package_pricing.package_id')->get()->groupBy('package_id');
        $packages = $this->db()->table('packages')->whereIn('id', $rows->pluck('package_id'))->pluck('module_id', 'id');

        foreach ($rows as $row) {
            $tld = strtolower(ltrim(trim((string) ($row->tld ?? '')), '.'));

            if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $tld)) {
                $this->counts['skipped']++;

                continue;
            }

            foreach ($prices->get($row->package_id, collect()) as $price) {
                $register = $this->money($price->price ?? 0);

                $tldPrice = TldPrice::query()->updateOrCreate(['tld' => $tld, 'currency' => strtoupper((string) $price->currency)], [
                    'registrar' => self::REGISTRAR_MODULES[$this->modules()[(int) ($packages[$row->package_id] ?? 0)] ?? ''] ?? null,
                    'register_price' => $register,
                    'renew_price' => $this->money($price->price_renews ?? null) ?: $register,
                    'transfer_price' => $this->money($price->price_transfer ?? null) ?: $register,
                    'is_enabled' => true,
                ]);

                $this->counts[$tldPrice->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        }

        $this->knownTlds = null;
    }

    /**
     * Services of registrar modules.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDomains(Collection $rows): void
    {
        $this->knownTlds ??= TldPrice::query()->distinct()->pluck('tld')->all();
        $fields = $this->serviceFields($rows->pluck('id')->all());
        $packages = $this->db()->table('packages')->whereIn('id', $rows->map(fn (stdClass $row): ?int => $this->pricing($row->pricing_id)?->package_id)->filter())->pluck('module_id', 'id');

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);
            $pricing = $this->pricing($row->pricing_id ?? 0);
            $values = $fields[$row->id] ?? [];
            $name = strtolower((string) $this->text(collect(self::DOMAIN_FIELDS)->map(fn (string $key): mixed => $values[$key] ?? null)->filter()->first()));

            if ($clientId === null || ! DomainName::isValid($name)) {
                $this->counts['skipped']++;

                continue;
            }

            $expires = $this->date($row->expiration_date ?? null) ?? $this->date($row->date_renews ?? null);
            $status = match ((string) ($row->status ?? '')) {
                'active' => $expires !== null && $expires < today()->toDateString() ? DomainStatus::Expired : DomainStatus::Active,
                'suspended' => DomainStatus::Expired,
                'canceled', 'cancelled' => DomainStatus::Cancelled,
                default => DomainStatus::Pending,
            };
            $amount = $this->money($row->override_price ?? null ?: ($pricing->price_renews ?? null ?: $pricing->price ?? 0));

            $this->upsert('domain', (int) $row->id, Domain::class, [
                'name' => $name,
                'tld' => DomainName::split($name, $this->knownTlds)[1],
                'registrar' => self::REGISTRAR_MODULES[$this->modules()[(int) ($packages[$pricing->package_id ?? 0] ?? 0)] ?? ''] ?? null,
                'status' => $status->value,
                'years' => ($pricing->period ?? '') === 'year' ? max(1, min(10, (int) $pricing->term)) : 1,
                'first_payment_amount' => $amount,
                'recurring_amount' => $amount,
                'registered_at' => $this->date($row->date_added ?? null),
                'expires_at' => $expires,
                'next_due_date' => $this->date($row->date_renews ?? null),
                'auto_renew' => ! in_array((string) ($row->status ?? ''), ['canceled', 'cancelled'], true),
            ], [
                'client_id' => $clientId,
                'currency' => $this->currencyCode($row->override_currency ?? null ?: ($pricing->currency ?? null), $this->clientCurrency($clientId)),
                'order_type' => Domain::TYPE_REGISTER,
            ]);
        }
    }

    /**
     * Invoices with their lines. Lines for services and domains are linked to them.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importInvoices(Collection $rows): void
    {
        $lines = $this->db()->table('invoice_lines')->whereIn('invoice_id', $rows->pluck('id'))->orderBy('order')->orderBy('id')->get()->groupBy('invoice_id');

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $subtotal = $this->money($row->subtotal ?? 0);
            $total = $this->money($row->total ?? 0);
            $paid = $this->money($row->paid ?? 0);
            $status = match ((string) ($row->status ?? '')) {
                'void' => InvoiceStatus::Cancelled,
                'draft' => InvoiceStatus::Draft,
                default => $this->date($row->date_closed ?? null) !== null || ($total > 0 && $paid >= $total) ? InvoiceStatus::Paid : InvoiceStatus::Unpaid,
            };
            $issued = $this->date($row->date_billed ?? null) ?? today()->toDateString();
            $localId = $this->localId('invoice', $row->id);
            $isNew = $localId === null;
            $wasDraft = ! $isNew && Invoice::query()->whereKey($localId)->toBase()->value('status') === InvoiceStatus::Draft->value;

            $invoice = $this->upsert('invoice', (int) $row->id, Invoice::class, [
                'status' => $status->value,
                'subtotal' => $subtotal,
                'tax' => max(0, $total - $subtotal),
                'total' => $total,
                'amount_paid' => $status === InvoiceStatus::Paid ? $total : min($total, max(0, $paid)),
                'issued_at' => $issued,
                'due_at' => $this->date($row->date_due ?? null) ?? $issued,
                'paid_at' => $status === InvoiceStatus::Paid ? ($this->date($row->date_closed ?? null) ?? $issued) : null,
                'notes' => $this->text($row->note_public ?? null),
            ], [
                'client_id' => $clientId,
                'currency' => $this->currencyCode($row->currency ?? null, $this->clientCurrency($clientId)),
                'number' => $status === InvoiceStatus::Draft ? null : $this->unique(Invoice::class, 'number', $this->invoiceNumber($row)),
            ]);

            // A draft finalized in Blesta since the last run gets its number, and a draft's lines may have changed.
            if ($wasDraft) {
                if ($invoice->status !== InvoiceStatus::Draft && $invoice->number === null) {
                    $invoice->forceFill(['number' => $this->unique(Invoice::class, 'number', $this->invoiceNumber($row))])->save();
                }

                $invoice->items()->delete();
            } elseif (! $isNew) {
                $this->freeBillingKeys($invoice);

                continue;
            }

            foreach ($lines->get($row->id, collect()) as $line) {
                $serviceId = (int) ($line->service_id ?? 0);
                $service = $serviceId > 0 ? Service::query()->find($this->localId('service', $serviceId)) : null;
                $domain = $serviceId > 0 && $service === null ? Domain::query()->find($this->localId('domain', $serviceId)) : null;
                $renewal = in_array($service?->status ?? null, [ServiceStatus::Active, ServiceStatus::Suspended], true) || $domain?->status === DomainStatus::Active;

                $this->addInvoiceItem(
                    $invoice,
                    $domain !== null ? ($renewal ? InvoiceItem::TYPE_DOMAIN_RENEW : InvoiceItem::TYPE_DOMAIN_REGISTER) : InvoiceItem::TYPE_SERVICE,
                    (string) ($line->description ?? '-'),
                    $this->money($line->amount ?? 0) * max(1, (int) round((float) ($line->qty ?? 1))),
                    $service,
                    $domain,
                    renewal: $renewal,
                );
            }
        }
    }

    /**
     * Blesta invoice numbers are a format, for example "INV-{num}", filled with the invoice's number.
     */
    private function invoiceNumber(stdClass $row): string
    {
        $format = (string) ($row->id_format ?? '') ?: '{num}';
        $billed = strtotime((string) ($row->date_billed ?? '')) ?: time();

        return strtr($format, [
            '{num}' => (string) ($row->id_value ?? $row->id),
            '{year}' => date('Y', $billed),
            '{month}' => date('m', $billed),
            '{day}' => date('d', $billed),
        ]);
    }

    /**
     * Approved payments. Blesta can spread one payment over several invoices; it is linked to the first.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTransactions(Collection $rows): void
    {
        $applied = $this->hasTable('transaction_applied')
            ? $this->db()->table('transaction_applied')->whereIn('transaction_id', $rows->pluck('id'))->orderBy('invoice_id')->get()->groupBy('transaction_id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);
            $amount = $this->money($row->amount ?? 0);

            if ($clientId === null || $amount === 0) {
                $this->counts['skipped']++;

                continue;
            }

            $gateway = $this->gatewaySlug($this->gatewayName($row->gateway_id ?? 0) ?? ($row->type ?? null));
            $reference = Str::limit($this->text($row->transaction_id ?? null) ?? 'blesta-'.$row->id, 180, '');

            if ($this->localId('transaction', $row->id) === null && Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->exists()) {
                $reference .= '-bl'.$row->id;
            }

            $this->upsert('transaction', (int) $row->id, Transaction::class, [
                'invoice_id' => $this->localId('invoice', $applied->get($row->id)?->first()?->invoice_id ?? 0),
                'type' => 'payment',
                'amount' => abs($amount),
                'paid_at' => $this->date($row->date_added ?? null) ?? now(),
            ], [
                'client_id' => $clientId,
                'gateway' => $gateway,
                'reference' => $reference,
                'currency' => $this->currencyCode($row->currency ?? null, $this->clientCurrency($clientId)),
                'meta' => ['blesta_id' => (int) $row->id],
            ]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDepartments(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->text($row->name ?? null) ?? 'Support';

            if (! $this->departmentToImport((int) $row->id, $name)) {
                continue;
            }

            $this->upsert('department', (int) $row->id, TicketDepartment::class, [
                'name' => $name,
                'email' => filter_var($row->email ?? null, FILTER_VALIDATE_EMAIL) ?: null,
                'description' => $this->text($row->description ?? null),
            ], ['is_visible' => true]);
        }
    }

    /**
     * Tickets with their replies. Staff notes and log lines are left out; so are tickets from guests.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTickets(Collection $rows): void
    {
        $replies = $this->hasTable('support_replies')
            ? $this->db()->table('support_replies')->whereIn('ticket_id', $rows->pluck('id'))->where('type', 'reply')->orderBy('date_added')->orderBy('id')->get()->groupBy('ticket_id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $thread = $replies->get($row->id, collect());
            $opened = $this->date($row->date_added ?? null) ?? now()->toDateTimeString();
            $lastReply = $this->date($thread->last()?->date_added ?? null) ?? $this->date($row->date_updated ?? null) ?? $opened;
            $status = match ((string) ($row->status ?? '')) {
                'closed' => TicketStatus::Closed,
                'on_hold' => TicketStatus::OnHold,
                'awaiting_reply' => TicketStatus::Answered,
                default => filled($thread->last()?->staff_id ?? null) ? TicketStatus::Answered : TicketStatus::Open,
            };

            $ticket = $this->upsert('ticket', (int) $row->id, Ticket::class, [
                'subject' => Str::limit($this->text($row->summary ?? null) ?? __('(no subject)'), 250),
                'status' => $status->value,
                'priority' => (match ((string) ($row->priority ?? '')) {
                    'emergency', 'critical', 'high' => TicketPriority::High,
                    'low' => TicketPriority::Low,
                    default => TicketPriority::Medium,
                })->value,
                'last_reply_at' => $lastReply,
                'closed_at' => $status === TicketStatus::Closed ? ($this->date($row->date_closed ?? null) ?? $lastReply) : null,
            ], [
                'number' => $this->unique(Ticket::class, 'number', (string) ($row->code ?? $row->id)),
                'client_id' => $clientId,
                'ticket_department_id' => $this->localId('department', $row->department_id ?? 0) ?? $this->departmentNamed(null),
                'service_id' => $this->localId('service', $row->service_id ?? 0),
                'created_at' => $opened,
            ]);

            foreach ($thread as $reply) {
                if ($this->localId('ticket_reply', $reply->id) !== null) {
                    continue;
                }

                $author = filled($reply->staff_id ?? null) ? ['admin', $this->localId('admin', $reply->staff_id)] : ['client', $clientId];
                $local = $this->addTicketReply($ticket, $author, (string) ($reply->details ?? ''), $this->date($reply->date_added ?? null) ?? $lastReply);
                $this->remember('ticket_reply', (int) $reply->id, $local->id);
            }
        }
    }

    public function preflight(): Preflight
    {
        $report = new Preflight(self::name(), $this->version());
        $this->addSteps($report);

        $contacts = $this->db()->table('clients')->leftJoin('contacts', fn ($join) => $join->on('contacts.client_id', '=', 'clients.id')->where('contacts.contact_type', 'primary'))->pluck('contacts.email');
        $this->checkEmails($report, $contacts);

        if ($this->secret === null) {
            $report->problem(Preflight::WARNING, $this->db()->table('clients')->count(), 'Client passwords that need the Blesta key: :count. Add the key, or clients choose a new password on the sign-in page.');
        }

        $servers = $this->db()->table('module_rows')->get(['id', 'module_id']);
        [$supported, $unsupported] = $servers->partition(fn (stdClass $row): bool => $this->serverModule($this->modules()[(int) $row->module_id] ?? null, self::SERVER_MODULES) !== null);
        $registrarRows = $this->moduleIds(array_keys(self::REGISTRAR_MODULES));
        $unsupported = $unsupported->reject(fn (stdClass $row): bool => in_array((int) $row->module_id, $registrarRows, true));
        $report->problem(Preflight::WARNING, $unsupported->count(), 'Servers with a module Nuvabill does not have: :count. They are skipped.', examples: $unsupported->map(fn (stdClass $row): string => (string) ($this->modules()[(int) $row->module_id] ?? '?'))->unique()->values()->all());
        $report->problem(Preflight::WARNING, $supported->count(), 'Servers whose password or API key Blesta encrypted: :count. Enter them in Servers after the import.');

        $weekly = $this->db()->table('pricings')->whereIn('period', ['day', 'week'])->count();
        $report->problem(Preflight::WARNING, $weekly, 'Prices with a billing period Nuvabill does not have: :count. They are not imported.');

        $unknownRegistrars = collect($this->modules())->filter(fn (string $class): bool => array_key_exists($class, self::REGISTRAR_MODULES) && self::REGISTRAR_MODULES[$class] === null);
        $report->problem(Preflight::INFO, $this->rows('domains')->whereIn('pricing_id', fn (Builder $query) => $query->select('package_pricing.id')->from('package_pricing')->join('packages', 'packages.id', '=', 'package_pricing.package_id')->whereIn('packages.module_id', $unknownRegistrars->keys()))->count(), 'Domains with a registrar Nuvabill does not have: :count. They are imported without one; renew them by hand.', examples: $unknownRegistrars->values()->all());

        if ($this->hasTable('package_option_values') && $this->hasTable('service_options')) {
            $report->problem(Preflight::WARNING, $this->db()->table('service_options')->count(), 'Configurable option values, not imported: :count.');
        }

        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0) {
            $overdue = $this->db()->table('services')->where('status', 'active')
                ->whereIn('id', fn (Builder $query) => $query->select('invoice_lines.service_id')->from('invoice_lines')
                    ->join('invoices', 'invoices.id', '=', 'invoice_lines.invoice_id')
                    ->where('invoices.status', 'active')->whereNull('invoices.date_closed')
                    ->where('invoices.date_due', '<=', today()->subDays($suspendDays)->toDateTimeString()))
                ->count();

            $report->problem(Preflight::WARNING, $overdue, 'Active services with an invoice more than :days days overdue: :count. Nuvabill\'s first nightly run suspends them.', ['days' => $suspendDays]);
        }

        $this->checkCurrencies($report, $this->hasTable('currencies') ? $this->db()->table('currencies')->pluck('code') : collect());
        $this->checkGateways($report, $this->hasTable('gateways') ? $this->db()->table('gateways')->pluck('class') : collect());

        $report->problem(Preflight::INFO, $this->clientsWithCredit(), 'Clients with credit: :count. It becomes their Nuvabill wallet balance.');

        if ($this->hasTable('support_tickets')) {
            $report->problem(Preflight::WARNING, $this->db()->table('support_tickets')->whereNull('client_id')->where('status', '!=', 'trash')->count(), 'Tickets from guests without a client account: :count. They are skipped.');
        }

        if ($this->hasTable('support_attachments')) {
            $report->problem(Preflight::INFO, $this->db()->table('support_attachments')->join('support_replies', 'support_replies.id', '=', 'support_attachments.reply_id')->distinct()->count('support_replies.ticket_id'), 'Tickets with attachments: :count. The messages are imported without the files.');
        }

        $this->automationAdvice($report);

        return $report;
    }

    /**
     * How many clients have money paid but not applied to an invoice, in their own currency (as the clients
     * step imports it). A few grouped queries, not two per client, so the dry run stays quick.
     */
    private function clientsWithCredit(): int
    {
        if (! $this->hasTable('transactions')) {
            return 0;
        }

        $key = fn (stdClass $row): string => $row->client_id.'|'.strtoupper((string) $row->currency);
        $paid = $this->db()->table('transactions')->where('status', 'approved')
            ->groupBy('client_id', 'currency')
            ->selectRaw('client_id, currency, SUM(amount) as total')
            ->get();
        $applied = $this->hasTable('transaction_applied')
            ? $this->db()->table('transaction_applied')->join('transactions', 'transactions.id', '=', 'transaction_applied.transaction_id')
                ->where('transactions.status', 'approved')
                ->groupBy('transactions.client_id', 'transactions.currency')
                ->selectRaw('transactions.client_id as client_id, transactions.currency as currency, SUM(transaction_applied.amount) as total')
                ->get()->keyBy($key)
            : collect();
        $currencies = $this->hasTable('client_settings')
            ? $this->db()->table('client_settings')->where('key', 'default_currency')
                ->when($this->hasColumn('client_settings', 'encrypted'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('encrypted')->orWhere('encrypted', 0)))
                ->pluck('value', 'client_id')
            : collect();

        return $paid
            ->filter(fn (stdClass $row): bool => strtoupper((string) $row->currency) === $this->currencyCode($currencies->get($row->client_id))
                && $this->money($row->total) - $this->money($applied->get($key($row))?->total ?? 0) > 0)
            ->pluck('client_id')->unique()->count();
    }

    protected function sourceClients(array $ids): ?array
    {
        return $this->keyClients($this->db()->table('contacts')->whereIn('client_id', $ids)->where('contact_type', 'primary')
            ->get(['client_id as id', 'email', 'date_added as created']));
    }

    /**
     * @return array<int, string> module ID => class
     */
    private function modules(): array
    {
        return $this->modules ??= $this->hasTable('modules') ? $this->db()->table('modules')->pluck('class', 'id')->map(fn (mixed $class): string => strtolower((string) $class))->all() : [];
    }

    /**
     * @param  list<string>  $classes
     * @return list<int>
     */
    private function moduleIds(array $classes): array
    {
        return array_keys(array_filter($this->modules(), fn (string $class): bool => in_array($class, $classes, true)));
    }

    private function pricing(mixed $packagePricingId): ?stdClass
    {
        if ($this->pricings === null) {
            $this->pricings = $this->db()->table('package_pricing')->join('pricings', 'pricings.id', '=', 'package_pricing.pricing_id')
                ->select('pricings.*', 'package_pricing.id as package_pricing_id', 'package_pricing.package_id')
                ->get()->keyBy('package_pricing_id')->all();
        }

        return $this->pricings[(int) $packagePricingId] ?? null;
    }

    /**
     * Readable service field values by service ID. Encrypted ones are left out.
     *
     * @param  list<int|string>  $serviceIds
     * @return array<int, array<string, string>>
     */
    private function serviceFields(array $serviceIds): array
    {
        $values = [];

        if (! $this->hasTable('service_fields')) {
            return $values;
        }

        foreach ($this->db()->table('service_fields')->whereIn('service_id', $serviceIds)->get() as $field) {
            if (! ($field->encrypted ?? false)) {
                $values[(int) $field->service_id][strtolower((string) $field->key)] = (string) $field->value;
            }
        }

        return $values;
    }

    /**
     * Readable meta values by key. Encrypted ones are left out; serialized ones are unpacked.
     *
     * @param  Collection<int, stdClass>  $meta
     * @return array<string, mixed>
     */
    private function metaValues(Collection $meta): array
    {
        $values = [];

        foreach ($meta as $row) {
            if ($row->encrypted ?? false) {
                continue;
            }

            $value = $row->value;

            if ($row->serialized ?? false) {
                $value = @unserialize((string) $value, ['allowed_classes' => false]);
            }

            $values[strtolower((string) $row->key)] = $value;
        }

        return $values;
    }

    /**
     * Client settings (default currency, tax ID) by client ID.
     *
     * @param  list<int|string>  $clientIds
     * @return array<int, array<string, string>>
     */
    private function clientSettings(array $clientIds): array
    {
        $values = [];

        if ($this->hasTable('client_settings')) {
            foreach ($this->db()->table('client_settings')->whereIn('client_id', $clientIds)->whereIn('key', ['default_currency', 'tax_id', 'tax_exempt'])->get() as $setting) {
                if (! ($setting->encrypted ?? false)) {
                    $values[(int) $setting->client_id][(string) $setting->key] = (string) $setting->value;
                }
            }
        }

        return $values;
    }

    private function gatewayName(mixed $gatewayId): ?string
    {
        $this->gateways ??= $this->hasTable('gateways') ? $this->db()->table('gateways')->pluck('class', 'id')->all() : [];

        return $this->text($this->gateways[(int) $gatewayId] ?? null);
    }

    private function currencyCode(mixed $code, ?string $fallback = null): string
    {
        $code = strtoupper(trim((string) $code));

        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        return $this->defaultCurrency ??= strtoupper((string) ($this->hasTable('company_settings') ? $this->db()->table('company_settings')->where('key', 'default_currency')->value('value') : null) ?: setting('billing.currency'));
    }
}
