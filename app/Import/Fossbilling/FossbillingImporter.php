<?php

namespace App\Import\Fossbilling;

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
use Illuminate\Support\Str;
use stdClass;

/**
 * Copies a FOSSBilling (or BoxBilling) installation into Nuvabill by reading its MySQL database.
 *
 * In FOSSBilling an order is the service: hosting and other orders become services, domain orders become
 * domains. Prices are kept in the default currency. Not imported: add-on products, weekly prices,
 * custom fields, and tickets from guests.
 */
class FossbillingImporter extends ImportSource
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
        'staff' => 'admin',
        'clients' => 'client',
        'product_groups' => 'product_category',
        'servers' => 'service_hosting_server',
        'products' => 'product',
        'services' => 'client_order',
        'tlds' => 'tld',
        'domains' => 'client_order',
        'invoices' => 'invoice',
        'transactions' => 'transaction',
        'departments' => 'support_helpdesk',
        'tickets' => 'support_ticket',
    ];

    /**
     * FOSSBilling server managers and the Nuvabill module each becomes, when it is installed.
     */
    private const SERVER_MODULES = [
        'whm' => 'cpanel',
        'cpanel' => 'cpanel',
        'directadmin' => 'directadmin',
        'plesk' => 'plesk',
        'hestia' => 'hestiacp',
        'hestiacp' => 'hestiacp',
        'cyberpanel' => 'cyberpanel',
    ];

    private const REGISTRARS = ['resellerclub', 'namecheap', 'enom', 'opensrs'];

    /**
     * FOSSBilling period => Nuvabill billing cycle, and the price columns of product_payment.
     */
    private const PERIODS = [
        '1M' => [BillingCycle::Monthly, 'm'],
        '3M' => [BillingCycle::Quarterly, 'q'],
        '6M' => [BillingCycle::SemiAnnually, 'b'],
        '1Y' => [BillingCycle::Annually, 'a'],
        '2Y' => [BillingCycle::Biennially, 'bia'],
        '3Y' => [BillingCycle::Triennially, 'tria'],
    ];

    /**
     * Orders store what they are in service_type; these become domains.
     */
    private const DOMAIN = 'domain';

    private ?string $defaultCurrency = null;

    /**
     * @var array<int, stdClass>|null
     */
    private ?array $registrars = null;

    /**
     * @var list<string>|null
     */
    private ?array $knownTlds = null;

    /**
     * @var array<int, string>|null
     */
    private ?array $gateways = null;

    public static function key(): string
    {
        return 'fossbilling';
    }

    public static function name(): string
    {
        return 'FOSSBilling';
    }

    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function configFile(): string
    {
        return 'config.php';
    }

    protected function tables(): array
    {
        return self::TABLES;
    }

    protected function requiredTables(): array
    {
        return ['client', 'client_order', 'invoice'];
    }

    protected function entities(): array
    {
        return ['staff' => 'admin', 'clients' => 'client', 'product_groups' => 'product_group', 'servers' => 'server', 'products' => 'product', 'services' => 'service', 'domains' => 'domain', 'invoices' => 'invoice', 'transactions' => 'transaction', 'departments' => 'department', 'tickets' => 'ticket'];
    }

    public function version(): string
    {
        return '';
    }

    protected function rows(string $step): Builder
    {
        $query = parent::rows($step);

        return match ($step) {
            'staff' => $this->hasColumn('admin', 'role') ? $query->where(fn (Builder $query) => $query->whereNull('role')->orWhere('role', '!=', 'cron')) : $query,
            'products' => $query->where(fn (Builder $query) => $query->whereNull('is_addon')->orWhere('is_addon', 0))->where(fn (Builder $query) => $query->whereNull('type')->orWhere('type', '!=', self::DOMAIN)),
            'services' => $query->where(fn (Builder $query) => $query->whereNull('service_type')->orWhere('service_type', '!=', self::DOMAIN)),
            'domains' => $query->where('service_type', self::DOMAIN),
            'transactions' => $query->whereIn('status', ['processed', 'approved']),
            default => $query,
        };
    }

    protected function legacyPassword(string $hash): ?string
    {
        return str_starts_with($hash, '$argon2') ? 'native:'.$hash : null;
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
                'name' => $this->text($row->name ?? null) ?? $email,
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'is_active' => false,
            ]);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importClients(Collection $rows): void
    {
        $balances = $this->hasTable('client_balance')
            ? $this->db()->table('client_balance')->whereIn('client_id', $rows->pluck('id'))->groupBy('client_id')->selectRaw('client_id, SUM(amount) as total')->pluck('total', 'client_id')->all()
            : [];

        foreach ($rows as $row) {
            if (($email = $this->clientEmailToImport((int) $row->id, $row->email ?? null, $this->currencyCode($row->currency ?? null))) === null) {
                continue;
            }

            $phone = trim((filled($row->phone_cc ?? null) ? '+'.ltrim((string) $row->phone_cc, '+').' ' : '').$this->text($row->phone ?? null));

            $client = $this->upsert('client', (int) $row->id, Client::class, [
                'first_name' => $this->text($row->first_name ?? null) ?? '',
                'last_name' => $this->text($row->last_name ?? null) ?? '',
                'company_name' => $this->text($row->company ?? null),
                'phone' => $phone !== '' ? $phone : null,
                'address_1' => $this->text($row->address_1 ?? null),
                'address_2' => $this->text($row->address_2 ?? null),
                'city' => $this->text($row->city ?? null),
                'state' => $this->text($row->state ?? null),
                'postcode' => Str::limit((string) $this->text($row->postcode ?? null), 20, '') ?: null,
                'country' => preg_match('/^[A-Za-z]{2}$/', (string) ($row->country ?? '')) ? strtoupper($row->country) : null,
                'status' => (match ((string) ($row->status ?? '')) {
                    'suspended' => ClientStatus::Inactive,
                    'canceled', 'cancelled' => ClientStatus::Closed,
                    default => ClientStatus::Active,
                })->value,
                'notes' => $this->text($row->notes ?? null),
                'tax_exempt' => (bool) ($row->tax_exempt ?? false),
                'tax_id' => $this->text($row->company_vat ?? null),
            ], [
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'currency' => $this->currencyCode($row->currency ?? null),
                'created_at' => $this->date($row->created_at ?? null) ?? now(),
            ]);

            if ($client->wasRecentlyCreated) {
                $this->applyPassword($client, $row->pass ?? null);
            }

            $this->syncCredit($client, max(0, $this->money($balances[$row->id] ?? 0)), (int) $row->id);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProductGroups(Collection $rows): void
    {
        foreach ($rows as $row) {
            $name = $this->text($row->title ?? null) ?? 'Group '.$row->id;

            $this->upsert('product_group', (int) $row->id, ProductGroup::class, [
                'name' => $name,
                'description' => $this->text($row->description ?? null),
            ], ['slug' => $this->unique(ProductGroup::class, 'slug', Str::slug($name) ?: 'group'), 'is_visible' => true]);
        }
    }

    /**
     * Servers are imported switched off, so staff check them before Nuvabill uses them.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServers(Collection $rows): void
    {
        foreach ($rows as $row) {
            $module = $this->serverModule($row->manager ?? null, self::SERVER_MODULES);

            if ($module === null) {
                $this->counts['skipped']++;

                continue;
            }

            $ip = filter_var($row->ip ?? null, FILTER_VALIDATE_IP) ?: null;
            $hostname = $this->text($row->hostname ?? null) ?? $ip ?? 'localhost';

            $server = $this->upsert('server', (int) $row->id, Server::class, [
                'name' => $this->text($row->name ?? null) ?? $hostname,
                'module' => $module,
                'hostname' => $hostname,
                'ip_address' => $ip,
                'port' => (int) ($row->port ?? 0) ?: null,
                'use_ssl' => (bool) ($row->secure ?? true),
                'username' => $this->text($row->username ?? null),
                'nameservers' => array_values(array_filter(array_map(fn (int $number): ?string => $this->text($row->{'ns'.$number} ?? null), range(1, 4)))),
                'max_accounts' => (int) ($row->max_accounts ?? 0) ?: null,
            ], ['is_active' => false]);

            // Only filled when empty, so passwords staff entered in Nuvabill are kept.
            $server->forceFill(array_filter([
                'password' => blank($server->password) ? $this->printable($row->password ?? null) : null,
                'api_token' => blank($server->api_token) ? (preg_replace('/\s+/', '', (string) ($row->accesshash ?? '')) ?: null) : null,
            ]))->save();
        }
    }

    /**
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProducts(Collection $rows): void
    {
        $payments = $this->hasTable('product_payment')
            ? $this->db()->table('product_payment')->whereIn('id', $rows->pluck('product_payment_id')->filter())->get()->keyBy('id')
            : collect();
        $plans = $this->hasTable('service_hosting_hp') ? $this->db()->table('service_hosting_hp')->pluck('name', 'id') : collect();
        $servers = $this->hasTable('service_hosting_server') ? $this->db()->table('service_hosting_server')->pluck('manager', 'id') : collect();

        foreach ($rows as $row) {
            $groupId = $this->localId('product_group', $row->product_category_id ?? 0);

            if ($groupId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $config = (array) json_decode((string) ($row->config ?? ''), true);
            $isHosting = ($row->type ?? '') === 'hosting';
            $module = $isHosting ? $this->serverModule($servers->get($config['server_id'] ?? 0), self::SERVER_MODULES) : null;
            $plan = (string) $this->text($plans->get($config['hosting_plan_id'] ?? 0));
            $name = $this->text($row->title ?? null) ?? 'Product '.$row->id;

            $product = $this->upsert('product', (int) $row->id, Product::class, [
                'product_group_id' => $groupId,
                'name' => $name,
                'type' => ($isHosting ? ProductType::Hosting : ProductType::Other)->value,
                'description' => $this->text($row->description ?? null),
                'is_visible' => ($row->status ?? 'enabled') === 'enabled' && ! ($row->hidden ?? false),
                'requires_domain' => $isHosting,
                'server_module' => $module,
                'auto_setup' => (match ((string) ($row->setup ?? '')) {
                    'after_order' => AutoSetup::OnOrder,
                    'after_payment' => AutoSetup::OnPayment,
                    default => AutoSetup::Manual,
                })->value,
                'stock' => ($row->stock_control ?? false) ? max(0, (int) ($row->quantity_in_stock ?? 0)) : null,
                'sort_order' => (int) ($row->priority ?? 0),
            ], [
                'slug' => $this->unique(Product::class, 'slug', Str::slug($this->text($row->slug ?? null) ?? $name) ?: 'product'),
                'module_config' => match ($module) {
                    'cpanel', 'directadmin' => ['package' => $plan],
                    'plesk' => ['plan' => $plan],
                    default => [],
                },
            ]);

            if ($payment = $payments->get($row->product_payment_id ?? 0)) {
                $this->importPrices($product, $payment);
            }
        }
    }

    private function importPrices(Product $product, stdClass $payment): void
    {
        $currency = $this->currencyCode(null);
        $prices = match ((string) ($payment->type ?? '')) {
            'free' => [BillingCycle::Free->value => [0, 0]],
            'once' => [BillingCycle::OneTime->value => [$this->money($payment->once_price ?? 0), $this->money($payment->once_setup_price ?? 0)]],
            default => collect(self::PERIODS)
                ->filter(fn (array $period): bool => (bool) ($payment->{$period[1].'_enabled'} ?? true))
                ->mapWithKeys(fn (array $period): array => [$period[0]->value => [$this->money($payment->{$period[1].'_price'} ?? 0), $this->money($payment->{$period[1].'_setup_price'} ?? 0)]])
                ->all(),
        };

        foreach ($prices as $cycle => [$price, $setup]) {
            ProductPrice::query()->updateOrCreate(
                ['product_id' => $product->id, 'currency' => $currency, 'billing_cycle' => $cycle],
                ['price' => max(0, $price), 'setup_fee' => max(0, $setup)],
            );
        }
    }

    /**
     * Hosting and other orders. The hosting details (domain, username, server) come from service_hosting.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importServices(Collection $rows): void
    {
        $hosting = $this->hasTable('service_hosting')
            ? $this->db()->table('service_hosting')->whereIn('id', $rows->where('service_type', 'hosting')->pluck('service_id')->filter())->get()->keyBy('id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);
            $productId = $this->localId('product', $row->product_id ?? 0);

            if ($clientId === null || $productId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $account = ($row->service_type ?? '') === 'hosting' ? $hosting->get($row->service_id ?? 0) : null;
            $cycle = $this->cycle($row->period ?? null, $row->price ?? 0);
            $status = $this->orderStatus($row->status ?? null);
            $amount = $this->money($row->price ?? 0) * max(1, (int) ($row->quantity ?? 1));

            $service = $this->upsert('service', (int) $row->id, Service::class, [
                'product_id' => $productId,
                'server_id' => $this->localId('server', $account->service_hosting_server_id ?? 0),
                'domain' => $account !== null ? (strtolower(trim($this->text($account->sld ?? null).$this->text($account->tld ?? null), '.')) ?: null) : null,
                'username' => $this->text($account->username ?? null),
                'status' => $status->value,
                'billing_cycle' => $cycle->value,
                'first_payment_amount' => $amount,
                'recurring_amount' => $cycle->isRecurring() ? $amount : 0,
                'registration_date' => $this->date($row->activated_at ?? null) ?? $this->date($row->created_at ?? null) ?? today()->toDateString(),
                'next_due_date' => $cycle->isRecurring() ? $this->date($row->expires_at ?? null) : null,
                'suspension_reason' => $status === ServiceStatus::Suspended ? $this->text($row->reason ?? null) : null,
            ], [
                'client_id' => $clientId,
                'currency' => $this->currencyCode($row->currency ?? null, $this->clientCurrency($clientId)),
            ]);

            // The status Nuvabill kept, which may not be the source's one (see keepLocalStatus()).
            $service->suspended_at = $service->status === ServiceStatus::Suspended ? ($service->suspended_at ?? $this->date($row->suspended_at ?? null) ?? now()) : null;

            if (blank($service->password) && ($password = $this->printable($account->pass ?? null)) !== null) {
                $service->password = $password;
            }

            $service->save();
        }
    }

    private function orderStatus(mixed $value): ServiceStatus
    {
        return match ((string) $value) {
            'active', 'failed_renew' => ServiceStatus::Active,
            'suspended' => ServiceStatus::Suspended,
            'canceled', 'cancelled' => ServiceStatus::Cancelled,
            default => ServiceStatus::Pending,
        };
    }

    private function cycle(mixed $period, mixed $price): BillingCycle
    {
        $period = strtoupper(trim((string) $period));

        return self::PERIODS[$period === '12M' ? '1Y' : $period][0]
            ?? ((float) $price > 0 ? BillingCycle::OneTime : BillingCycle::Free);
    }

    /**
     * Domain prices in the default currency.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTlds(Collection $rows): void
    {
        foreach ($rows as $row) {
            $tld = strtolower(ltrim(trim((string) ($row->tld ?? '')), '.'));

            if (! preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)*$/', $tld)) {
                $this->counts['skipped']++;

                continue;
            }

            $register = $this->money($row->price_registration ?? 0);

            $tldPrice = TldPrice::query()->updateOrCreate(['tld' => $tld, 'currency' => $this->currencyCode(null)], [
                'registrar' => $this->registrar($row->tld_registrar_id ?? 0),
                'register_price' => $register,
                'transfer_price' => $this->money($row->price_transfer ?? 0) ?: $register,
                'renew_price' => $this->money($row->price_renew ?? 0) ?: $register,
                'epp_required' => (bool) ($row->require_transfer_code ?? true),
                'is_enabled' => (bool) ($row->active ?? true) && (bool) ($row->allow_register ?? true),
            ]);

            $this->counts[$tldPrice->wasRecentlyCreated ? 'created' : 'updated']++;
        }

        $this->knownTlds = null;
    }

    /**
     * Domain orders, with the domain's details from service_domain.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importDomains(Collection $rows): void
    {
        $this->knownTlds ??= TldPrice::query()->distinct()->pluck('tld')->all();
        $details = $this->hasTable('service_domain')
            ? $this->db()->table('service_domain')->whereIn('id', $rows->pluck('service_id')->filter())->get()->keyBy('id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);
            $domain = $details->get($row->service_id ?? 0);
            $name = $domain !== null ? strtolower(trim($this->text($domain->sld ?? null).'.'.ltrim((string) $this->text($domain->tld ?? null), '.'), '.')) : '';

            if ($clientId === null || ! DomainName::isValid($name)) {
                $this->counts['skipped']++;

                continue;
            }

            $expires = $this->date($domain->expires_at ?? null);
            $status = match ((string) ($row->status ?? '')) {
                'active', 'failed_renew' => $expires !== null && $expires < today()->toDateString() ? DomainStatus::Expired : DomainStatus::Active,
                'suspended' => DomainStatus::Expired,
                'canceled', 'cancelled' => DomainStatus::Cancelled,
                default => ($domain->action ?? '') === 'transfer' ? DomainStatus::PendingTransfer : DomainStatus::Pending,
            };

            $this->upsert('domain', (int) $row->id, Domain::class, [
                'name' => $name,
                'tld' => DomainName::split($name, $this->knownTlds)[1],
                'registrar' => $this->registrar($domain->tld_registrar_id ?? 0),
                'order_type' => ($domain->action ?? '') === 'transfer' ? Domain::TYPE_TRANSFER : Domain::TYPE_REGISTER,
                'status' => $status->value,
                'years' => max(1, min(10, (int) ($domain->period ?? 1))),
                'first_payment_amount' => $this->money($row->price ?? 0),
                'recurring_amount' => $this->money($row->price ?? 0),
                'registered_at' => $this->date($domain->registered_at ?? null),
                'expires_at' => $expires,
                'next_due_date' => $this->date($row->expires_at ?? null) ?? $expires,
                'auto_renew' => ! in_array((string) ($row->status ?? ''), ['canceled', 'cancelled'], true),
            ], [
                'client_id' => $clientId,
                'currency' => $this->currencyCode($row->currency ?? null, $this->clientCurrency($clientId)),
            ]);
        }
    }

    /**
     * Invoices with their lines. Lines for orders are linked to the service or domain.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importInvoices(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $items = $this->db()->table('invoice_item')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
        $payments = $this->hasTable('transaction')
            ? $this->db()->table('transaction')->whereIn('invoice_id', $ids)->whereIn('status', ['processed', 'approved'])->groupBy('invoice_id')->selectRaw('invoice_id, SUM(amount) as paid')->pluck('paid', 'invoice_id')->all()
            : [];
        $orderTypes = $this->db()->table('client_order')->whereIn('id', $items->flatten()->where('type', 'order')->pluck('rel_id')->filter())->pluck('service_type', 'id');

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $status = match ((string) ($row->status ?? '')) {
                'paid' => InvoiceStatus::Paid,
                'refunded' => InvoiceStatus::Refunded,
                'canceled', 'cancelled' => InvoiceStatus::Cancelled,
                default => InvoiceStatus::Unpaid,
            };

            $lines = $items->get($row->id, collect());
            $subtotal = (int) $lines->sum(fn (stdClass $item): int => $this->money($item->price ?? 0) * max(1, (int) ($item->quantity ?? 1)));
            $taxable = (int) $lines->filter(fn (stdClass $item): bool => (bool) ($item->taxed ?? false))->sum(fn (stdClass $item): int => $this->money($item->price ?? 0) * max(1, (int) ($item->quantity ?? 1)));
            $tax = (int) round($taxable * (float) ($row->taxrate ?? 0) / 100);
            $total = $subtotal + $tax;
            $issued = $this->date($row->created_at ?? null) ?? today()->toDateString();
            $isNew = $this->localId('invoice', $row->id) === null;
            $number = filled($row->nr ?? null) ? trim($this->text($row->serie ?? null).str_pad((string) $row->nr, 5, '0', STR_PAD_LEFT)) : (string) $row->id;

            $invoice = $this->upsert('invoice', (int) $row->id, Invoice::class, [
                'status' => $status->value,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'amount_paid' => $status === InvoiceStatus::Paid ? $total : min($total, max(0, $this->money($row->credit ?? 0) + $this->money($payments[$row->id] ?? 0))),
                'issued_at' => $issued,
                'due_at' => $this->date($row->due_at ?? null) ?? $issued,
                'paid_at' => $status === InvoiceStatus::Paid ? ($this->date($row->paid_at ?? null) ?? $issued) : null,
                'payment_method' => $this->gatewayName($row->gateway_id ?? 0),
                'notes' => $this->text($row->notes ?? null),
            ], [
                'client_id' => $clientId,
                'currency' => $this->currencyCode($row->currency ?? null, $this->clientCurrency($clientId)),
                'number' => $this->unique(Invoice::class, 'number', $number),
            ]);

            if (! $isNew) {
                $this->freeBillingKeys($invoice);

                continue;
            }

            foreach ($lines as $item) {
                $orderId = ($item->type ?? '') === 'order' ? (int) $item->rel_id : 0;
                $isDomain = ($orderTypes[$orderId] ?? null) === self::DOMAIN;
                $renewal = ($item->task ?? '') === 'renew';
                $service = $orderId > 0 && ! $isDomain ? Service::query()->find($this->localId('service', $orderId)) : null;
                $domain = $isDomain ? Domain::query()->find($this->localId('domain', $orderId)) : null;
                $type = match (true) {
                    $domain !== null => $renewal ? InvoiceItem::TYPE_DOMAIN_RENEW : ($domain->order_type === Domain::TYPE_TRANSFER ? InvoiceItem::TYPE_DOMAIN_TRANSFER : InvoiceItem::TYPE_DOMAIN_REGISTER),
                    default => InvoiceItem::TYPE_SERVICE,
                };

                $this->addInvoiceItem($invoice, $type, (string) ($item->title ?? '-'), $this->money($item->price ?? 0) * max(1, (int) ($item->quantity ?? 1)), $service, $domain, renewal: $renewal);
            }
        }
    }

    /**
     * Payments that FOSSBilling processed. The client comes from the invoice.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTransactions(Collection $rows): void
    {
        foreach ($rows as $row) {
            $invoice = Invoice::query()->find($this->localId('invoice', $row->invoice_id ?? 0));
            $amount = $this->money($row->amount ?? 0);

            if ($invoice === null || $amount === 0) {
                $this->counts['skipped']++;

                continue;
            }

            $gateway = $this->gatewaySlug($this->gatewayName($row->gateway_id ?? 0));
            $reference = Str::limit($this->text($row->txn_id ?? null) ?? 'fossbilling-'.$row->id, 180, '');

            if ($this->localId('transaction', $row->id) === null && Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->exists()) {
                $reference .= '-fb'.$row->id;
            }

            $this->upsert('transaction', (int) $row->id, Transaction::class, [
                'invoice_id' => $invoice->id,
                'type' => str_contains(strtolower((string) ($row->type ?? '')), 'refund') ? 'refund' : 'payment',
                'amount' => abs($amount),
                'paid_at' => $this->date($row->created_at ?? null) ?? now(),
            ], [
                'client_id' => $invoice->client_id,
                'gateway' => $gateway,
                'reference' => $reference,
                'currency' => $this->currencyCode($row->currency ?? null, $invoice->currency),
                'meta' => array_filter(['fossbilling_id' => (int) $row->id, 'note' => $this->text($row->note ?? null)]),
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
            ], ['is_visible' => true]);
        }
    }

    /**
     * Tickets with every message. Tickets without a client are skipped.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTickets(Collection $rows): void
    {
        $messages = $this->hasTable('support_ticket_message')
            ? $this->db()->table('support_ticket_message')->whereIn('support_ticket_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('support_ticket_id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->client_id ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $thread = $messages->get($row->id, collect());
            $opened = $this->date($row->created_at ?? null) ?? now()->toDateTimeString();
            $lastReply = $this->date($thread->last()?->created_at ?? null) ?? $this->date($row->updated_at ?? null) ?? $opened;
            $status = match ((string) ($row->status ?? '')) {
                'closed' => TicketStatus::Closed,
                'on_hold' => TicketStatus::OnHold,
                default => filled($thread->last()?->admin_id ?? null) ? TicketStatus::Answered : TicketStatus::Open,
            };

            $ticket = $this->upsert('ticket', (int) $row->id, Ticket::class, [
                'subject' => Str::limit($this->text($row->subject ?? null) ?? __('(no subject)'), 250),
                'status' => $status->value,
                'last_reply_at' => $lastReply,
                'closed_at' => $status === TicketStatus::Closed ? $lastReply : null,
            ], [
                'number' => $this->unique(Ticket::class, 'number', (string) $row->id),
                'client_id' => $clientId,
                'ticket_department_id' => $this->localId('department', $row->support_helpdesk_id ?? 0) ?? $this->departmentNamed(null),
                'priority' => TicketPriority::Medium->value,
                'created_at' => $opened,
            ]);

            foreach ($thread as $message) {
                if ($this->localId('ticket_reply', $message->id) !== null) {
                    continue;
                }

                $author = filled($message->admin_id ?? null)
                    ? ['admin', $this->localId('admin', $message->admin_id)]
                    : ['client', $clientId];

                $reply = $this->addTicketReply($ticket, $author, (string) ($message->content ?? ''), $this->date($message->created_at ?? null) ?? $lastReply, $message->ip ?? null);
                $this->remember('ticket_reply', (int) $message->id, $reply->id);
            }
        }
    }

    public function preflight(): Preflight
    {
        $report = new Preflight(self::name(), $this->version());
        $this->addSteps($report);

        $clients = $this->db()->table('client')->get(['email', 'pass']);
        $this->checkEmails($report, $clients->pluck('email'));
        $this->checkPasswords($report, $clients->pluck('pass'));

        if ($this->hasTable('service_hosting_server')) {
            $unsupported = $this->db()->table('service_hosting_server')->get(['name', 'manager'])->filter(fn (stdClass $row): bool => $this->serverModule($row->manager, self::SERVER_MODULES) === null);
            $report->problem(Preflight::WARNING, $unsupported->count(), 'Servers with a module Nuvabill does not have: :count. They are skipped.', examples: $unsupported->map(fn (stdClass $row): string => $this->text($row->name).' ('.$row->manager.')')->all());
            $report->problem(Preflight::INFO, $this->db()->table('service_hosting_server')->count() - $unsupported->count(), 'Servers are imported switched off: :count. Check them in Servers, then switch them on.');
        }

        $report->problem(Preflight::WARNING, $this->db()->table('product')->where('is_addon', 1)->count(), 'Add-on products, not imported: :count.');

        if ($this->hasTable('product_payment')) {
            $report->problem(Preflight::INFO, $this->db()->table('product_payment')->where('type', 'recurrent')->where('w_enabled', 1)->where('w_price', '>', 0)->count(), 'Weekly prices, not imported: :count. Nuvabill bills monthly or longer.');
        }

        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0 && $this->hasTable('invoice_item')) {
            $overdue = $this->db()->table('client_order')
                ->where('status', 'active')
                ->where(fn (Builder $query) => $query->whereNull('service_type')->orWhere('service_type', '!=', self::DOMAIN))
                ->whereIn('id', fn (Builder $query) => $query->select('rel_id')->from('invoice_item')
                    ->join('invoice', 'invoice.id', '=', 'invoice_item.invoice_id')
                    ->where('invoice_item.type', 'order')
                    ->where('invoice.status', 'unpaid')
                    ->where('invoice.due_at', '<=', today()->subDays($suspendDays)->toDateTimeString()))
                ->count();

            $report->problem(Preflight::WARNING, $overdue, 'Active services with an invoice more than :days days overdue: :count. Nuvabill\'s first nightly run suspends them.', ['days' => $suspendDays]);
        }

        if ($this->hasTable('service_domain') && $this->hasTable('tld_registrar')) {
            $other = $this->db()->table('service_domain')->join('tld_registrar', 'tld_registrar.id', '=', 'service_domain.tld_registrar_id')
                ->whereNotIn('tld_registrar.registrar', array_merge(self::REGISTRARS, array_map('ucfirst', self::REGISTRARS)))->pluck('tld_registrar.registrar');
            $report->problem(Preflight::INFO, $other->count(), 'Domains with a registrar Nuvabill does not have: :count. They are imported without one; renew them by hand.', examples: $other->unique()->values()->all());
        }

        $this->checkCurrencies($report, $this->hasTable('currency') ? $this->db()->table('currency')->pluck('code') : collect());
        $this->checkGateways($report, $this->hasTable('pay_gateway') ? $this->db()->table('pay_gateway')->pluck('gateway') : collect());

        if ($this->hasTable('client_balance')) {
            $report->problem(Preflight::INFO, $this->db()->table('client_balance')->groupBy('client_id')->havingRaw('SUM(amount) > 0')->pluck('client_id')->count(), 'Clients with credit: :count. It becomes their Nuvabill wallet balance.');
        }

        $report->problem(Preflight::WARNING, $this->hasTable('support_p_ticket') ? $this->db()->table('support_p_ticket')->count() : 0, 'Tickets from guests without a client account: :count. They are skipped.');

        if ($this->hasTable('support_ticket_message')) {
            $report->problem(Preflight::INFO, $this->db()->table('support_ticket_message')->whereNotNull('attachment')->where('attachment', '!=', '')->distinct()->count('support_ticket_id'), 'Tickets with attachments: :count. The messages are imported without the files.');
        }

        $this->automationAdvice($report);

        return $report;
    }

    protected function sourceClients(array $ids): ?array
    {
        return $this->keyClients($this->db()->table('client')->whereIn('id', $ids)->get(['id', 'email', 'created_at as created']));
    }

    /**
     * The registrar a FOSSBilling registrar record uses, when Nuvabill has it.
     */
    private function registrar(mixed $registrarId): ?string
    {
        if ($this->registrars === null) {
            $this->registrars = $this->hasTable('tld_registrar') ? $this->db()->table('tld_registrar')->get()->keyBy('id')->all() : [];
        }

        $name = strtolower((string) ($this->registrars[(int) $registrarId]->registrar ?? ''));

        return in_array($name, self::REGISTRARS, true) ? $name : null;
    }

    private function gatewayName(mixed $gatewayId): ?string
    {
        $this->gateways ??= $this->hasTable('pay_gateway') ? $this->db()->table('pay_gateway')->pluck('gateway', 'id')->all() : [];

        return $this->text($this->gateways[(int) $gatewayId] ?? null);
    }

    /**
     * A currency code, or the default currency.
     */
    private function currencyCode(mixed $code, ?string $fallback = null): string
    {
        $code = strtoupper(trim((string) $code));

        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        if ($fallback !== null) {
            return $fallback;
        }

        return $this->defaultCurrency ??= strtoupper((string) ($this->hasTable('currency') ? $this->db()->table('currency')->where('is_default', 1)->value('code') : null) ?: setting('billing.currency'));
    }

    /**
     * A stored password when it is readable text. FOSSBilling keeps hosting passwords as entered.
     */
    private function printable(mixed $value): ?string
    {
        $value = (string) $value;

        return $value !== '' && mb_check_encoding($value, 'UTF-8') && ! preg_match('/[\x00-\x1F\x7F]/', $value) ? $value : null;
    }
}
