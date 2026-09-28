<?php

namespace App\Import\Whmcs;

use App\Auth\LegacyPassword;
use App\Billing\RenewalGenerator;
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
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
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
use App\Models\TicketReply;
use App\Models\TldPrice;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use stdClass;

/**
 * Copies a WHMCS installation into Nuvabill by reading its MySQL database.
 *
 * With WHMCS's encryption key (cc_encryption_hash from configuration.php) server and service
 * passwords come across too; without it they are left empty. Not imported: saved cards, addons,
 * configurable options and custom fields (except a Virtualizor "vpsid").
 */
class WhmcsImporter extends ImportSource
{
    public const SOURCE = 'whmcs';

    public const CONNECTION = 'whmcs_import';

    /**
     * Steps in the order they run. Later steps need the IDs from earlier ones.
     *
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
        'staff' => 'tbladmins',
        'clients' => 'tblclients',
        'product_groups' => 'tblproductgroups',
        'servers' => 'tblservers',
        'products' => 'tblproducts',
        'services' => 'tblhosting',
        'tlds' => 'tbldomainpricing',
        'domains' => 'tbldomains',
        'invoices' => 'tblinvoices',
        'transactions' => 'tblaccounts',
        'departments' => 'tblticketdepartments',
        'tickets' => 'tbltickets',
    ];

    /**
     * WHMCS server module names and the Nuvabill module each becomes.
     */
    private const SERVER_MODULES = [
        'cpanel' => 'cpanel',
        'directadmin' => 'directadmin',
        'plesk' => 'plesk',
        'virtualizor' => 'virtualizor',
        'proxmox' => 'proxmox',
        'proxmoxvps' => 'proxmox',
    ];

    private const REGISTRARS = ['resellerclub', 'namecheap', 'enom', 'opensrs'];

    /**
     * WHMCS payment module names and the Nuvabill gateway each becomes. Others keep their WHMCS name.
     */
    private const GATEWAYS = [
        'paypal' => 'paypal',
        'paypalcheckout' => 'paypal',
        'paypal_ppcpv' => 'paypal',
        'stripe' => 'stripe',
        'stripe_checkout' => 'stripe',
        'banktransfer' => 'banktransfer',
        'mailin' => 'banktransfer',
    ];

    /**
     * Billing cycle => [price column, setup fee column] in tblpricing.
     */
    private const CYCLE_COLUMNS = [
        'monthly' => ['monthly', 'msetupfee'],
        'quarterly' => ['quarterly', 'qsetupfee'],
        'semiannually' => ['semiannually', 'ssetupfee'],
        'annually' => ['annually', 'asetupfee'],
        'biennially' => ['biennially', 'bsetupfee'],
        'triennially' => ['triennially', 'tsetupfee'],
    ];

    /**
     * @var array<int, string>|null
     */
    private ?array $currencies = null;

    private ?string $defaultCurrency = null;

    /**
     * @var array<string, int|null>|null
     */
    private ?array $staffByName = null;

    /**
     * @var list<string>|null
     */
    private ?array $knownTlds = null;

    public function __construct(string $connection = self::CONNECTION, ?string $secret = null)
    {
        parent::__construct($connection, $secret);
    }

    public static function connect(array $credentials, string $connection = self::CONNECTION): static
    {
        return parent::connect($credentials, $connection);
    }

    public static function key(): string
    {
        return self::SOURCE;
    }

    public static function name(): string
    {
        return 'WHMCS';
    }

    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function keyHelp(): ?string
    {
        return 'Optional. The value of $cc_encryption_hash in configuration.php. With it, server and service passwords come across too.';
    }

    public static function configFile(): string
    {
        return 'configuration.php';
    }

    protected function tables(): array
    {
        return self::TABLES;
    }

    protected function requiredTables(): array
    {
        return ['tblclients', 'tblinvoices'];
    }

    public function version(): string
    {
        return $this->hasTable('tblconfiguration') ? (string) $this->db()->table('tblconfiguration')->where('setting', 'Version')->value('value') : '';
    }

    /**
     * Staff become switched-off accounts, so ticket replies keep their author. Existing staff with the same email are linked.
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
                'name' => trim($this->text($row->firstname ?? null).' '.$this->text($row->lastname ?? null)) ?: $email,
                'email' => $email,
                'password' => Str::random(40),
                'is_active' => false,
            ]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importClients(Collection $rows): void
    {
        $passwords = $this->ownerPasswords($rows->pluck('id')->all());

        foreach ($rows as $row) {
            $email = strtolower((string) $this->text($row->email ?? null));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $this->counts['skipped']++;

                continue;
            }

            if ($this->localId('client', $row->id) === null && ($existing = Client::query()->where('email', $email)->first())) {
                $this->remember('client', (int) $row->id, $existing->id);
                $this->counts['updated']++;

                continue;
            }

            $client = $this->upsert('client', (int) $row->id, Client::class, [
                'first_name' => $this->text($row->firstname ?? null) ?? '',
                'last_name' => $this->text($row->lastname ?? null) ?? '',
                'company_name' => $this->text($row->companyname ?? null),
                'phone' => $this->text($row->phonenumber ?? null),
                'address_1' => $this->text($row->address1 ?? null),
                'address_2' => $this->text($row->address2 ?? null),
                'city' => $this->text($row->city ?? null),
                'state' => $this->text($row->state ?? null),
                'postcode' => Str::limit((string) $this->text($row->postcode ?? null), 20, '') ?: null,
                'country' => preg_match('/^[A-Za-z]{2}$/', (string) ($row->country ?? '')) ? strtoupper($row->country) : null,
                'status' => (match ((string) ($row->status ?? '')) {
                    'Inactive' => ClientStatus::Inactive,
                    'Closed' => ClientStatus::Closed,
                    default => ClientStatus::Active,
                })->value,
                'notes' => $this->text($row->notes ?? null),
            ], [
                'email' => $email,
                'password' => Str::random(40),
                'currency' => $this->currency($row->currency ?? 0),
                'created_at' => $this->date($row->datecreated ?? null) ?? now(),
            ]);

            if ($client->wasRecentlyCreated) {
                $this->importPassword($client, (string) ($passwords[$row->id] ?? $row->password ?? ''));
            }

            $this->syncCredit($client, $this->money($row->credit ?? 0), (int) $row->id);
        }
    }

    /**
     * WHMCS 7+ keeps bcrypt hashes, which work as they are. WHMCS 4.2 to 6.2 kept salted MD5, which is
     * checked at the first sign-in. Very old versions kept passwords encrypted, which the key opens.
     */
    private function importPassword(Client $client, string $stored): void
    {
        if ($this->secret !== null && ! str_contains($stored, '$') && ! preg_match('/^[a-f0-9]{32}:/i', $stored)
            && ($plain = (new WhmcsCrypt($this->secret))->decrypt($stored)) !== null) {
            DB::table('clients')->where('id', $client->id)->update(['password' => Hash::make($plain)]);

            return;
        }

        $this->applyPassword($client, $stored);
    }

    protected function legacyPassword(string $hash): ?string
    {
        if (preg_match('/^([a-f0-9]{32}):(.{1,32})$/i', $hash, $match)) {
            return 'md5-salt:'.strtolower($match[1]).':'.$match[2];
        }

        return str_starts_with($hash, '$argon2') ? 'native:'.$hash : null;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProductGroups(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->text($row->name ?? null) ?? 'Group '.$row->id;

            $this->upsert('product_group', (int) $row->id, ProductGroup::class, [
                'name' => $name,
                'description' => $this->text($row->headline ?? null) ?? $this->text($row->tagline ?? null),
                'is_visible' => ! ($row->hidden ?? false),
                'sort_order' => (int) ($row->order ?? 0),
            ], ['slug' => $this->unique(ProductGroup::class, 'slug', Str::slug($name) ?: 'group')]);
        }
    }

    /**
     * Servers are imported switched off, so staff check them before Nuvabill uses them. With the WHMCS key
     * their password comes across; the access hash (API token) is not encrypted in WHMCS.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServers(Collection $rows): void
    {
        foreach ($rows as $row) {
            $module = self::SERVER_MODULES[strtolower((string) ($row->type ?? ''))] ?? null;

            if ($module === null) {
                $this->counts['skipped']++;

                continue;
            }

            $ip = filter_var($row->ipaddress ?? null, FILTER_VALIDATE_IP) ?: null;
            $hostname = $this->text($row->hostname ?? null) ?? $ip ?? 'localhost';
            $password = $this->decrypt($row->password ?? null);
            $token = preg_replace('/\s+/', '', (string) ($row->accesshash ?? '')) ?: null;

            $server = $this->upsert('server', (int) $row->id, Server::class, [
                'name' => $this->text($row->name ?? null) ?? $hostname,
                'module' => $module,
                'hostname' => $hostname,
                'ip_address' => $ip,
                'port' => (int) ($row->port ?? 0) ?: null,
                'use_ssl' => ($row->secure ?? '') === 'on',
                'username' => $this->text($row->username ?? null),
                'nameservers' => array_values(array_filter(array_map(fn (int $number): ?string => $this->text($row->{'nameserver'.$number} ?? null), range(1, 5)))),
                'max_accounts' => (int) ($row->maxaccounts ?? 0) ?: null,
            ], ['is_active' => false]);

            // Only filled when empty, so passwords staff entered in Nuvabill are kept.
            $server->forceFill(array_filter([
                'password' => blank($server->password) ? $password : null,
                'api_token' => blank($server->api_token) ? $token : null,
            ]))->save();
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProducts(Collection $rows): void
    {
        $prices = $this->hasTable('tblpricing')
            ? $this->db()->table('tblpricing')->where('type', 'product')->whereIn('relid', $rows->pluck('id'))->get()->groupBy('relid')
            : collect();

        foreach ($rows as $row) {
            $groupId = $this->localId('product_group', $row->gid ?? 0);

            if ($groupId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $module = self::SERVER_MODULES[strtolower((string) ($row->servertype ?? ''))] ?? null;
            $name = $this->text($row->name ?? null) ?? 'Product '.$row->id;

            $product = $this->upsert('product', (int) $row->id, Product::class, [
                'product_group_id' => $groupId,
                'name' => $name,
                'type' => (match ((string) ($row->type ?? '')) {
                    'reselleraccount' => ProductType::Reseller,
                    'server' => ProductType::Server,
                    'other' => ProductType::Other,
                    default => ProductType::Hosting,
                })->value,
                'description' => $this->text($row->description ?? null),
                'is_visible' => ! ($row->hidden ?? false) && ! ($row->retired ?? false),
                'requires_domain' => (bool) ($row->showdomainoptions ?? true),
                'server_module' => $module,
                'auto_setup' => (match ((string) ($row->autosetup ?? '')) {
                    'order' => AutoSetup::OnOrder,
                    'payment' => AutoSetup::OnPayment,
                    default => AutoSetup::Manual,
                })->value,
                'stock' => ($row->stockcontrol ?? false) ? max(0, (int) ($row->qty ?? 0)) : null,
                'sort_order' => (int) ($row->order ?? 0),
            ], [
                'slug' => $this->unique(Product::class, 'slug', Str::slug($name) ?: 'product'),
                'module_config' => match ($module) {
                    'cpanel', 'directadmin' => ['package' => (string) $this->text($row->configoption1 ?? null)],
                    'plesk' => ['plan' => (string) $this->text($row->configoption1 ?? null)],
                    default => [],
                },
            ]);

            $this->importPrices($product, (string) ($row->paytype ?? 'recurring'), $prices->get($row->id, collect()));
        }
    }

    /**
     * WHMCS marks a cycle that is not offered with a price of -1.
     *
     * @param  Collection<int, stdClass>  $prices
     */
    private function importPrices(Product $product, string $payType, Collection $prices): void
    {
        if ($payType === 'free') {
            ProductPrice::query()->updateOrCreate(
                ['product_id' => $product->id, 'currency' => $this->currency(0), 'billing_cycle' => BillingCycle::Free->value],
                ['price' => 0, 'setup_fee' => 0],
            );

            return;
        }

        foreach ($prices as $price) {
            $cycles = $payType === 'onetime'
                ? [BillingCycle::OneTime->value => ['monthly', 'msetupfee']]
                : self::CYCLE_COLUMNS;

            foreach ($cycles as $cycle => [$priceColumn, $setupColumn]) {
                if (! is_numeric($price->{$priceColumn} ?? null) || (float) $price->{$priceColumn} < 0) {
                    continue;
                }

                ProductPrice::query()->updateOrCreate(
                    ['product_id' => $product->id, 'currency' => $this->currency($price->currency ?? 0), 'billing_cycle' => $cycle],
                    ['price' => $this->money($price->{$priceColumn}), 'setup_fee' => max(0, $this->money($price->{$setupColumn} ?? 0))],
                );
            }
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServices(Collection $rows): void
    {
        $vpsIds = $this->customFieldValues('vpsid', $rows->pluck('id')->all());

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);
            $productId = $this->localId('product', $row->packageid ?? 0);

            if ($clientId === null || $productId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $cycle = $this->cycle($row->billingcycle ?? '');
            $status = $this->serviceStatus($row->domainstatus ?? '');

            $service = $this->upsert('service', (int) $row->id, Service::class, [
                'product_id' => $productId,
                'server_id' => $this->localId('server', $row->server ?? 0),
                'domain' => $this->text($row->domain ?? null),
                'username' => $this->text($row->username ?? null),
                'status' => $status->value,
                'billing_cycle' => $cycle->value,
                'first_payment_amount' => $this->money($row->firstpaymentamount ?? 0),
                'recurring_amount' => $this->money($row->amount ?? 0),
                'registration_date' => $this->date($row->regdate ?? null) ?? today()->toDateString(),
                'next_due_date' => $cycle->isRecurring() ? $this->date($row->nextduedate ?? null) : null,
                'suspension_reason' => $status === ServiceStatus::Suspended ? $this->text($row->suspendreason ?? null) : null,
                'terminated_at' => $status === ServiceStatus::Terminated ? ($this->date($row->termination_date ?? null) ?? now()) : null,
            ], [
                'client_id' => $clientId,
                'currency' => $this->clientCurrency($clientId),
                'module_data' => isset($vpsIds[$row->id]) ? ['vpsid' => (string) $vpsIds[$row->id]] : null,
            ]);

            $service->suspended_at = $status === ServiceStatus::Suspended ? ($service->suspended_at ?? now()) : null;

            if (blank($service->password) && ($password = $this->decrypt($row->password ?? null)) !== null) {
                $service->password = $password;
            }

            $service->save();
        }
    }

    private function serviceStatus(mixed $value): ServiceStatus
    {
        return match ((string) $value) {
            'Active', 'Completed' => ServiceStatus::Active,
            'Suspended' => ServiceStatus::Suspended,
            'Terminated' => ServiceStatus::Terminated,
            'Cancelled' => ServiceStatus::Cancelled,
            'Fraud' => ServiceStatus::Fraud,
            default => ServiceStatus::Pending,
        };
    }

    /**
     * Prices for one year, per currency. Extensions WHMCS does not sell (register price -1) are skipped.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTlds(Collection $rows): void
    {
        $prices = $this->hasTable('tblpricing')
            ? $this->db()->table('tblpricing')->whereIn('type', ['domainregister', 'domaintransfer', 'domainrenew'])->whereIn('relid', $rows->pluck('id'))->get()->groupBy('relid')
            : collect();

        foreach ($rows as $row) {
            $tld = strtolower(ltrim(trim((string) ($row->extension ?? '')), '.'));

            if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $tld)) {
                $this->counts['skipped']++;

                continue;
            }

            $registrar = strtolower((string) ($row->autoreg ?? ''));

            foreach ($prices->get($row->id, collect())->groupBy('currency') as $currencyId => $group) {
                $byType = $group->keyBy('type');
                $register = $byType->get('domainregister')?->msetupfee;

                if (! is_numeric($register) || (float) $register < 0) {
                    continue;
                }

                $price = fn (string $type): int => is_numeric($byType->get($type)?->msetupfee) && (float) $byType->get($type)->msetupfee >= 0
                    ? $this->money($byType->get($type)->msetupfee)
                    : $this->money($register);

                $tldPrice = TldPrice::query()->updateOrCreate(['tld' => $tld, 'currency' => $this->currency($currencyId)], [
                    'registrar' => in_array($registrar, self::REGISTRARS, true) ? $registrar : null,
                    'register_price' => $this->money($register),
                    'transfer_price' => $price('domaintransfer'),
                    'renew_price' => $price('domainrenew'),
                    'epp_required' => (bool) ($row->eppcode ?? true),
                    'is_enabled' => true,
                    'sort_order' => (int) ($row->order ?? 0),
                ]);

                $this->counts[$tldPrice->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        }

        $this->knownTlds = null;
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDomains(Collection $rows): void
    {
        $this->knownTlds ??= TldPrice::query()->distinct()->pluck('tld')->all();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);
            $name = strtolower((string) $this->text($row->domain ?? null));

            if ($clientId === null || ! DomainName::isValid($name)) {
                $this->counts['skipped']++;

                continue;
            }

            $registrar = strtolower((string) ($row->registrar ?? ''));

            $this->upsert('domain', (int) $row->id, Domain::class, [
                'name' => $name,
                'tld' => DomainName::split($name, $this->knownTlds)[1],
                'registrar' => in_array($registrar, self::REGISTRARS, true) ? $registrar : null,
                'order_type' => strtolower((string) ($row->type ?? '')) === 'transfer' ? Domain::TYPE_TRANSFER : Domain::TYPE_REGISTER,
                'status' => (match ((string) ($row->status ?? '')) {
                    'Active' => DomainStatus::Active,
                    'Pending Transfer' => DomainStatus::PendingTransfer,
                    'Grace', 'Redemption', 'Expired' => DomainStatus::Expired,
                    'Transferred Away' => DomainStatus::TransferredAway,
                    'Cancelled' => DomainStatus::Cancelled,
                    'Fraud' => DomainStatus::Fraud,
                    default => DomainStatus::Pending,
                })->value,
                'years' => max(1, min(10, (int) ($row->registrationperiod ?? 1))),
                'first_payment_amount' => $this->money($row->firstpaymentamount ?? 0),
                'recurring_amount' => $this->money($row->recurringamount ?? 0),
                'registered_at' => $this->date($row->registrationdate ?? null),
                'expires_at' => $this->date($row->expirydate ?? null),
                'next_due_date' => $this->date($row->nextduedate ?? null),
                'auto_renew' => ! ($row->donotrenew ?? false),
            ], [
                'client_id' => $clientId,
                'currency' => $this->clientCurrency($clientId),
            ]);
        }
    }

    /**
     * Invoices with their lines. Credit applied in WHMCS counts as paid, like account credit in Nuvabill.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importInvoices(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $payments = $this->hasTable('tblaccounts')
            ? $this->db()->table('tblaccounts')->whereIn('invoiceid', $ids)->groupBy('invoiceid')->selectRaw('invoiceid, SUM(amountin) - SUM(amountout) as paid')->pluck('paid', 'invoiceid')->all()
            : [];
        $items = $this->hasTable('tblinvoiceitems')
            ? $this->db()->table('tblinvoiceitems')->whereIn('invoiceid', $ids)->orderBy('id')->get()->groupBy('invoiceid')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $status = match ((string) ($row->status ?? '')) {
                'Paid' => InvoiceStatus::Paid,
                'Cancelled' => InvoiceStatus::Cancelled,
                'Refunded' => InvoiceStatus::Refunded,
                'Draft' => InvoiceStatus::Draft,
                default => InvoiceStatus::Unpaid,
            };

            $subtotal = $this->money($row->subtotal ?? 0);
            $tax = $this->money($row->tax ?? 0) + $this->money($row->tax2 ?? 0);
            $total = $subtotal + $tax;
            $paid = $status === InvoiceStatus::Paid ? $total : min($total, max(0, $this->money($row->credit ?? 0) + $this->money($payments[$row->id] ?? 0)));
            $isNew = $this->localId('invoice', $row->id) === null;
            $issued = $this->date($row->date ?? null) ?? today()->toDateString();

            $invoice = $this->upsert('invoice', (int) $row->id, Invoice::class, [
                'status' => $status->value,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => $paid,
                'issued_at' => $issued,
                'due_at' => $this->date($row->duedate ?? null) ?? $issued,
                'paid_at' => $status === InvoiceStatus::Paid ? ($this->date($row->datepaid ?? null) ?? $issued) : null,
                'payment_method' => $this->text($row->paymentmethod ?? null),
                'notes' => $this->text($row->notes ?? null),
            ], [
                'client_id' => $clientId,
                'currency' => $this->clientCurrency($clientId),
                'number' => $status === InvoiceStatus::Draft ? null : $this->unique(Invoice::class, 'number', $this->text($row->invoicenum ?? null) ?? (string) $row->id),
            ]);

            if ($isNew) {
                foreach ($items->get($row->id, collect()) as $item) {
                    $this->importInvoiceItem($invoice, $item);
                }
            } elseif ($status === InvoiceStatus::Cancelled) {
                // Cancelled in WHMCS after the first run: its periods may be invoiced again.
                $invoice->items()->whereNotNull('billing_key')->update(['billing_key' => null]);
            }
        }
    }

    /**
     * Lines for services and domains keep their period, so paying an imported renewal invoice in Nuvabill
     * renews them, and Nuvabill never invoices the same period again.
     */
    private function importInvoiceItem(Invoice $invoice, stdClass $item): void
    {
        $type = match ((string) ($item->type ?? '')) {
            'Hosting' => InvoiceItem::TYPE_SERVICE,
            'Setup' => InvoiceItem::TYPE_SETUP,
            'DomainRegister' => InvoiceItem::TYPE_DOMAIN_REGISTER,
            'DomainTransfer' => InvoiceItem::TYPE_DOMAIN_TRANSFER,
            'Domain' => InvoiceItem::TYPE_DOMAIN_RENEW,
            default => InvoiceItem::TYPE_MANUAL,
        };

        $service = in_array($type, [InvoiceItem::TYPE_SERVICE, InvoiceItem::TYPE_SETUP], true) ? Service::query()->find($this->localId('service', $item->relid ?? 0)) : null;
        $domain = in_array($type, InvoiceItem::DOMAIN_TYPES, true) ? Domain::query()->find($this->localId('domain', $item->relid ?? 0)) : null;
        $start = $this->date($item->duedate ?? null);
        $start = $start !== null ? CarbonImmutable::parse($start) : null;

        $end = match (true) {
            $start === null => null,
            $type === InvoiceItem::TYPE_SERVICE && $service?->billing_cycle->isRecurring() => $service->billing_cycle->advance($start)->subDay(),
            $domain !== null => $start->addYears($domain->years)->subDay(),
            default => null,
        };

        $key = match (true) {
            $end === null || $invoice->status === InvoiceStatus::Cancelled => null,
            $type === InvoiceItem::TYPE_SERVICE && $service !== null => RenewalGenerator::billingKey('service', $service->id, $start),
            $type === InvoiceItem::TYPE_DOMAIN_RENEW && $domain !== null => RenewalGenerator::billingKey('domain', $domain->id, $start),
            default => null,
        };

        // WHMCS may hold two invoices for one period; only the first keeps the key.
        if ($key !== null && InvoiceItem::query()->where('billing_key', $key)->exists()) {
            $key = null;
        }

        $invoice->items()->create([
            'service_id' => $service?->id,
            'domain_id' => $domain?->id,
            'type' => $service === null && $domain === null ? InvoiceItem::TYPE_MANUAL : $type,
            'description' => Str::limit($this->text($item->description ?? null) ?? '-', 250),
            'amount' => $this->money($item->amount ?? 0),
            'period_start' => $end !== null ? $start : null,
            'period_end' => $end,
            'billing_key' => $key,
        ]);
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTransactions(Collection $rows): void
    {
        foreach ($rows as $row) {
            $invoiceId = $this->localId('invoice', $row->invoiceid ?? 0);
            $clientId = $this->localId('client', $row->userid ?? 0) ?? ($invoiceId !== null ? Invoice::query()->whereKey($invoiceId)->value('client_id') : null);
            $received = $this->money($row->amountin ?? 0);
            $refunded = $this->money($row->amountout ?? 0);

            if ($clientId === null || ($received === 0 && $refunded === 0)) {
                $this->counts['skipped']++;

                continue;
            }

            $gateway = $this->gateway($row->gateway ?? null);
            $reference = Str::limit($this->text($row->transid ?? null) ?? 'whmcs-'.$row->id, 180, '');

            if ($this->localId('transaction', $row->id) === null && Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->exists()) {
                $reference .= '-whmcs'.$row->id;
            }

            $this->upsert('transaction', (int) $row->id, Transaction::class, [
                'invoice_id' => $invoiceId,
                'type' => $refunded > 0 ? 'refund' : 'payment',
                'amount' => $refunded > 0 ? $refunded : $received,
                'fee' => max(0, $this->money($row->fees ?? 0)),
                'paid_at' => $this->date($row->date ?? null) ?? now(),
            ], [
                'client_id' => $clientId,
                'gateway' => $gateway,
                'reference' => $reference,
                'currency' => $this->currency($row->currency ?? 0, $this->clientCurrency($clientId)),
                'meta' => array_filter(['whmcs_id' => (int) $row->id, 'description' => $this->text($row->description ?? null)]),
            ]);
        }
    }

    private function gateway(mixed $name): string
    {
        $name = strtolower((string) $this->text($name));

        return self::GATEWAYS[$name] ?? (Str::limit($name, 60, '') ?: 'manual');
    }

    /**
     * Departments with the same name as an existing one are linked to it.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDepartments(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->text($row->name ?? null) ?? 'Support';

            if ($this->localId('department', $row->id) === null && ($existing = TicketDepartment::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first())) {
                $this->remember('department', (int) $row->id, $existing->id);
                $this->counts['updated']++;

                continue;
            }

            $email = filter_var($row->email ?? null, FILTER_VALIDATE_EMAIL) ?: null;

            $this->upsert('department', (int) $row->id, TicketDepartment::class, [
                'name' => $name,
                'email' => $email,
                'description' => $this->text($row->description ?? null),
                'is_visible' => ! ($row->hidden ?? false),
                'sort_order' => (int) ($row->order ?? 0),
            ]);
        }
    }

    /**
     * Tickets with their messages. Tickets from guests (no client account) are skipped.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTickets(Collection $rows): void
    {
        $replies = $this->hasTable('tblticketreplies')
            ? $this->db()->table('tblticketreplies')->whereIn('tid', $rows->pluck('id'))->orderBy('id')->get()->groupBy('tid')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->userid ?? 0);
            $departmentId = $this->localId('department', $row->did ?? 0);

            if ($clientId === null || $departmentId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $status = match ((string) ($row->status ?? '')) {
                'Answered' => TicketStatus::Answered,
                'Customer-Reply' => TicketStatus::CustomerReply,
                'On Hold' => TicketStatus::OnHold,
                'Closed' => TicketStatus::Closed,
                default => TicketStatus::Open,
            };

            $opened = $this->date($row->date ?? null) ?? now()->toDateTimeString();
            $lastReply = $this->date($row->lastreply ?? null) ?? $opened;
            $isNew = $this->localId('ticket', $row->id) === null;

            $ticket = $this->upsert('ticket', (int) $row->id, Ticket::class, [
                'subject' => Str::limit($this->text($row->title ?? null) ?? __('(no subject)'), 250),
                'status' => $status->value,
                'priority' => (match (strtolower((string) ($row->urgency ?? ''))) {
                    'low' => TicketPriority::Low,
                    'high' => TicketPriority::High,
                    default => TicketPriority::Medium,
                })->value,
                'last_reply_at' => $lastReply,
                'closed_at' => $status === TicketStatus::Closed ? $lastReply : null,
            ], [
                'number' => $this->unique(Ticket::class, 'number', $this->text($row->tid ?? null) ?? (string) $row->id),
                'client_id' => $clientId,
                'ticket_department_id' => $departmentId,
                'service_id' => preg_match('/^S(\d+)$/', (string) ($row->service ?? ''), $match) ? $this->localId('service', $match[1]) : null,
                'created_at' => $opened,
            ]);

            if ($isNew) {
                $this->addReply($ticket, ['client', $clientId], (string) ($row->message ?? ''), $opened, $row->ipaddress ?? null);
            }

            foreach ($replies->get($row->id, collect()) as $reply) {
                if ($this->localId('ticket_reply', $reply->id) !== null) {
                    continue;
                }

                $author = filled($reply->admin ?? null)
                    ? ['admin', $this->staffId((string) $this->text($reply->admin))]
                    : ['client', $this->localId('client', $reply->userid ?? 0) ?? $clientId];

                $local = $this->addReply($ticket, $author, (string) ($reply->message ?? ''), $this->date($reply->date ?? null) ?? $lastReply, null);
                $this->remember('ticket_reply', (int) $reply->id, $local->id);
            }
        }
    }

    /**
     * @param  array{0: string, 1: int|null}  $author
     */
    private function addReply(Ticket $ticket, array $author, string $message, string $at, ?string $ip): TicketReply
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

    protected function entities(): array
    {
        return ['staff' => 'admin', 'clients' => 'client', 'product_groups' => 'product_group', 'servers' => 'server', 'products' => 'product', 'services' => 'service', 'domains' => 'domain', 'invoices' => 'invoice', 'transactions' => 'transaction', 'departments' => 'department', 'tickets' => 'ticket'];
    }

    /**
     * What an import would do, read without changing anything.
     */
    public function preflight(): Preflight
    {
        $report = new Preflight(self::name(), $this->version());

        $this->addSteps($report);
        $this->preflightClients($report);
        $this->preflightCatalog($report);
        $this->preflightBilling($report);
        $this->preflightSupport($report);

        $this->automationAdvice($report);

        return $report;
    }

    private function preflightClients(Preflight $report): void
    {
        $clients = $this->db()->table('tblclients')->get(['id', 'email', 'password']);
        $owners = $this->ownerPasswords($clients->pluck('id')->all());
        $hashes = $clients->map(fn (stdClass $row): string => (string) ($owners[$row->id] ?? $row->password ?? ''));
        $encrypted = $this->secret === null ? collect() : $hashes->filter(fn (string $hash): bool => ! LegacyPassword::isBcrypt($hash) && $this->legacyPassword($hash) === null && (new WhmcsCrypt($this->secret))->decrypt($hash) !== null);

        $this->checkEmails($report, $clients->pluck('email'));
        $this->checkPasswords($report, $hashes->diffKeys($encrypted));
        $report->problem(Preflight::INFO, $encrypted->count(), 'Client passwords that WHMCS encrypted: :count. The key opens them, so they keep working.');

        if ($this->hasTable('tblusers_clients')) {
            $report->problem(Preflight::INFO, $this->db()->table('tblusers_clients')->where('owner', 0)->count(), 'Extra users of client accounts: :count. They are not imported; the account owner signs in.');
        }
    }

    private function preflightCatalog(Preflight $report): void
    {
        if ($this->hasTable('tblservers')) {
            $servers = $this->db()->table('tblservers')->get(['id', 'name', 'type', 'password']);
            [$supported, $unsupported] = $servers->partition(fn (stdClass $row): bool => isset(self::SERVER_MODULES[strtolower((string) $row->type)]));
            $withPassword = $supported->filter(fn (stdClass $row): bool => filled($row->password));
            $unreadable = $withPassword->filter(fn (stdClass $row): bool => $this->decrypt($row->password) === null)->count();

            $report->problem(Preflight::WARNING, $unsupported->count(), 'Servers with a module Nuvabill does not have: :count. They are skipped.', examples: $unsupported->map(fn (stdClass $row): string => $this->text($row->name).' ('.$row->type.')')->all());
            $report->problem(Preflight::INFO, $supported->count(), 'Servers are imported switched off: :count. Check them in Servers, then switch them on.');

            if ($this->secret === null) {
                $report->problem(Preflight::WARNING, $unreadable, 'Server passwords that need the WHMCS key: :count. Add the key, or enter the passwords in Servers after the import.');
            } elseif ($unreadable > 0 && $unreadable === $withPassword->count()) {
                $report->problem(Preflight::ERROR, $unreadable, 'The WHMCS key did not open any of the :count server passwords. Check that it is the value of $cc_encryption_hash in configuration.php.');
            } else {
                $report->problem(Preflight::WARNING, $unreadable, 'Server passwords this key could not open: :count. Enter them in Servers after the import.');
            }
        }

        if ($this->hasTable('tblproducts')) {
            $products = $this->db()->table('tblproducts')->where('servertype', '!=', '')->whereNotIn(DB::raw('LOWER(servertype)'), array_keys(self::SERVER_MODULES))->get(['name', 'servertype']);
            $report->problem(Preflight::WARNING, $products->count(), 'Products with a module Nuvabill does not have: :count. They are imported for manual setup.', examples: $products->map(fn (stdClass $row): string => $this->text($row->name).' ('.$row->servertype.')')->all());
        }

        $count = fn (string $table): int => $this->hasTable($table) ? $this->db()->table($table)->count() : 0;
        $report->problem(Preflight::WARNING, $count('tblhostingaddons'), 'Addons on services, not imported: :count.');
        $report->problem(Preflight::WARNING, $count('tblhostingconfigoptions'), 'Configurable option values, not imported: :count.');
        $report->problem(Preflight::WARNING, $count('tblcustomfieldsvalues'), 'Custom field values, not imported: :count.');

        if ($this->hasTable('tbldomains')) {
            $other = $this->db()->table('tbldomains')->where('registrar', '!=', '')->whereNotIn(DB::raw('LOWER(registrar)'), self::REGISTRARS);
            $report->problem(Preflight::INFO, (clone $other)->count(), 'Domains with a registrar Nuvabill does not have: :count. They are imported without one; renew them by hand.', examples: $other->distinct()->pluck('registrar')->all());
        }
    }

    private function preflightBilling(Preflight $report): void
    {
        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0 && $this->hasTable('tblhosting') && $this->hasTable('tblinvoiceitems')) {
            $overdue = $this->db()->table('tblhosting')
                ->where('domainstatus', 'Active')
                ->whereIn('id', fn ($query) => $query->select('relid')->from('tblinvoiceitems')
                    ->join('tblinvoices', 'tblinvoices.id', '=', 'tblinvoiceitems.invoiceid')
                    ->where('tblinvoiceitems.type', 'Hosting')
                    ->where('tblinvoices.status', 'Unpaid')
                    ->where('tblinvoices.duedate', '>', '0000-00-00')
                    ->where('tblinvoices.duedate', '<=', today()->subDays($suspendDays)->toDateString()))
                ->count();

            $report->problem(Preflight::WARNING, $overdue, 'Active services with an invoice more than :days days overdue: :count. Nuvabill\'s first nightly run suspends them.', ['days' => $suspendDays]);
        }

        if ($this->hasTable('tblcurrencies')) {
            $known = array_merge([strtoupper((string) setting('billing.currency'))], array_keys((array) setting('currency.rates', [])));
            $other = $this->db()->table('tblcurrencies')->pluck('code')->map(fn (mixed $code): string => strtoupper((string) $code))->reject(fn (string $code): bool => in_array($code, $known, true));
            $report->problem(Preflight::INFO, $other->count(), 'Currencies not set up in Nuvabill: :count. Their clients and invoices keep them; add exchange rates in Settings.', examples: $other->values()->all());
        }

        if ($this->hasTable('tblaccounts')) {
            $known = array_merge(array_keys(self::GATEWAYS), app(ExtensionManager::class)->ofType(ExtensionManifest::TYPE_GATEWAY)->keys()->all());
            $gateways = $this->db()->table('tblaccounts')->distinct()->pluck('gateway')->map(fn (mixed $name): string => strtolower((string) $name))->filter()->reject(fn (string $name): bool => in_array($name, $known, true));
            $report->problem(Preflight::INFO, $gateways->count(), 'Payment methods not in Nuvabill: :count. Old payments keep their name.', examples: $gateways->values()->all());
        }

        $report->problem(Preflight::INFO, $this->db()->table('tblclients')->pluck('credit')->filter(fn (mixed $credit): bool => (float) $credit > 0)->count(), 'Clients with credit: :count. It becomes their Nuvabill wallet balance.');
    }

    private function preflightSupport(Preflight $report): void
    {
        if ($this->hasTable('tbltickets')) {
            $report->problem(Preflight::WARNING, $this->db()->table('tbltickets')->where('userid', 0)->count(), 'Tickets from guests without a client account: :count. They are skipped.');
            $report->problem(Preflight::INFO, $this->hasColumn('tbltickets', 'attachment') ? $this->db()->table('tbltickets')->where('attachment', '!=', '')->count() : 0, 'Tickets with attachments: :count. The messages are imported without the files.');
        }
    }

    /**
     * A value WHMCS encrypted with its own key, or null without the key or when it cannot be read.
     */
    private function decrypt(mixed $value): ?string
    {
        return $this->secret !== null && filled($value) ? (new WhmcsCrypt($this->secret))->decrypt((string) $value) : null;
    }

    /**
     * WHMCS 8 keeps passwords on users (tblusers); the owner of each client account signs in with it.
     *
     * @param  list<int|string>  $clientIds
     * @return array<int, string>
     */
    private function ownerPasswords(array $clientIds): array
    {
        if (! $this->hasTable('tblusers_clients') || ! $this->hasTable('tblusers')) {
            return [];
        }

        return $this->db()->table('tblusers_clients')
            ->join('tblusers', 'tblusers.id', '=', 'tblusers_clients.auth_user_id')
            ->whereIn('tblusers_clients.client_id', $clientIds)
            ->where('tblusers_clients.owner', 1)
            ->pluck('tblusers.password', 'tblusers_clients.client_id')
            ->all();
    }

    /**
     * Values of a product custom field (for example "vpsid") by WHMCS service ID.
     *
     * @param  list<int|string>  $serviceIds
     * @return array<int, string>
     */
    private function customFieldValues(string $fieldName, array $serviceIds): array
    {
        if (! $this->hasTable('tblcustomfields') || ! $this->hasTable('tblcustomfieldsvalues')) {
            return [];
        }

        return $this->db()->table('tblcustomfieldsvalues as v')
            ->join('tblcustomfields as f', 'f.id', '=', 'v.fieldid')
            ->where('f.type', 'product')
            ->where('f.fieldname', 'like', $fieldName.'%')
            ->whereIn('v.relid', $serviceIds)
            ->where('v.value', '!=', '')
            ->pluck('v.value', 'v.relid')
            ->all();
    }

    private function staffId(string $name): ?int
    {
        if ($this->staffByName === null) {
            $this->staffByName = [];

            if ($this->hasTable('tbladmins')) {
                foreach ($this->db()->table('tbladmins')->get(['id', 'firstname', 'lastname']) as $admin) {
                    $this->staffByName[mb_strtolower(trim($this->text($admin->firstname).' '.$this->text($admin->lastname)))] = $this->localId('admin', $admin->id);
                }
            }
        }

        return $this->staffByName[mb_strtolower(trim($name))] ?? null;
    }

    private function cycle(mixed $value): BillingCycle
    {
        return match (strtolower(str_replace(['-', ' '], '', (string) $value))) {
            'quarterly' => BillingCycle::Quarterly,
            'semiannually' => BillingCycle::SemiAnnually,
            'annually' => BillingCycle::Annually,
            'biennially' => BillingCycle::Biennially,
            'triennially' => BillingCycle::Triennially,
            'onetime' => BillingCycle::OneTime,
            'free', 'freeaccount' => BillingCycle::Free,
            default => BillingCycle::Monthly,
        };
    }

    /**
     * The currency code for a WHMCS currency ID. ID 0 means "the client's currency" in some tables.
     */
    private function currency(mixed $currencyId, ?string $fallback = null): string
    {
        if ($this->currencies === null) {
            $this->currencies = [];

            if ($this->hasTable('tblcurrencies')) {
                foreach ($this->db()->table('tblcurrencies')->orderBy('id')->get() as $currency) {
                    $this->currencies[(int) $currency->id] = strtoupper((string) $currency->code);

                    if ($currency->default ?? false) {
                        $this->defaultCurrency = strtoupper((string) $currency->code);
                    }
                }
            }
        }

        return $this->currencies[(int) $currencyId] ?? $fallback ?? $this->defaultCurrency ?? (string) setting('billing.currency');
    }
}
