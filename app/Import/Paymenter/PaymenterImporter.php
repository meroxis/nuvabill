<?php

namespace App\Import\Paymenter;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ProductType;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Import\ImportSource;
use App\Import\Preflight;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Support\Countries;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use stdClass;

/**
 * Copies a Paymenter 1.x installation into Nuvabill by reading its MySQL database.
 *
 * Paymenter keeps staff and clients in one users table; everyone becomes a client, and users with a
 * role also become staff. Addresses come from user properties. Not imported: server connections (set
 * them up in Nuvabill), coupons, and prices billed by the hour, day or week.
 */
class PaymenterImporter extends ImportSource
{
    /**
     * @var array<string, string>
     */
    public const STEPS = [
        'staff' => 'Staff',
        'clients' => 'Clients',
        'product_groups' => 'Product groups',
        'products' => 'Products and prices',
        'services' => 'Services',
        'invoices' => 'Invoices',
        'transactions' => 'Payments',
        'tickets' => 'Tickets',
    ];

    private const TABLES = [
        'staff' => 'users',
        'clients' => 'users',
        'product_groups' => 'categories',
        'products' => 'products',
        'services' => 'services',
        'invoices' => 'invoices',
        'transactions' => 'invoice_transactions',
        'tickets' => 'tickets',
    ];

    /**
     * User property keys and the client field each fills.
     */
    private const USER_PROPERTIES = [
        'company_name' => 'company_name',
        'company' => 'company_name',
        'address' => 'address_1',
        'address2' => 'address_2',
        'city' => 'city',
        'state' => 'state',
        'zip' => 'postcode',
        'postcode' => 'postcode',
        'country' => 'country',
        'phone' => 'phone',
    ];

    private ?string $defaultCurrency = null;

    /**
     * @var array<int, stdClass>|null
     */
    private ?array $plans = null;

    /**
     * @var array<int, string>|null
     */
    private ?array $gateways = null;

    public static function key(): string
    {
        return 'paymenter';
    }

    public static function name(): string
    {
        return 'Paymenter';
    }

    public static function steps(): array
    {
        return self::STEPS;
    }

    public static function configFile(): string
    {
        return '.env';
    }

    protected function tables(): array
    {
        return self::TABLES;
    }

    protected function requiredTables(): array
    {
        return ['users', 'services', 'invoices'];
    }

    protected function entities(): array
    {
        return ['staff' => 'admin', 'clients' => 'client', 'product_groups' => 'product_group', 'products' => 'product', 'services' => 'service', 'invoices' => 'invoice', 'transactions' => 'transaction', 'tickets' => 'ticket'];
    }

    public function version(): string
    {
        return $this->hasTable('settings') && $this->hasColumn('settings', 'key')
            ? (string) $this->db()->table('settings')->where('key', 'version')->value('value')
            : '';
    }

    protected function rows(string $step): Builder
    {
        $query = parent::rows($step);

        return match ($step) {
            'staff' => $query->whereNotNull('role_id'),
            'transactions' => $query
                ->when($this->hasColumn('invoice_transactions', 'status'), fn (Builder $query) => $query->where('status', 'succeeded'))
                ->when($this->hasColumn('invoice_transactions', 'is_credit_transaction'), fn (Builder $query) => $query->where(fn (Builder $query) => $query->whereNull('is_credit_transaction')->orWhere('is_credit_transaction', 0))),
            default => $query,
        };
    }

    protected function legacyPassword(string $hash): ?string
    {
        return str_starts_with($hash, '$argon2') ? 'native:'.$hash : null;
    }

    /**
     * Users with a role become switched-off staff, so ticket replies keep their author.
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
     * Every user becomes a client, so services and invoices of staff come across too.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importClients(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $properties = $this->properties('User', $ids);
        $currencies = $this->db()->table('invoices')->whereIn('user_id', $ids)->orderBy('id')->pluck('currency_code', 'user_id');
        $credits = $this->hasTable('credits')
            ? $this->db()->table('credits')->whereIn('user_id', $ids)->get()->groupBy('user_id')
            : collect();

        foreach ($rows as $row) {
            if (($email = $this->clientEmailToImport((int) $row->id, $row->email ?? null, $this->currencyCode($currencies[$row->id] ?? null))) === null) {
                continue;
            }

            $details = [];

            foreach ($properties[$row->id] ?? [] as $key => $value) {
                if (isset(self::USER_PROPERTIES[$key])) {
                    $details[self::USER_PROPERTIES[$key]] ??= $this->text($value);
                }
            }

            $details['postcode'] = Str::limit((string) ($details['postcode'] ?? ''), 20, '') ?: null;
            $details['country'] = $this->countryCode($details['country'] ?? null);

            // Paymenter has no client status: new clients start active, and a status staff set in Nuvabill is kept.
            $client = $this->upsert('client', (int) $row->id, Client::class, [
                'first_name' => $this->text($row->first_name ?? null) ?? '',
                'last_name' => $this->text($row->last_name ?? null) ?? '',
            ] + $details, [
                'status' => ClientStatus::Active->value,
                'email' => $email,
                'password' => $this->placeholderPassword(),
                'currency' => $this->currencyCode($currencies[$row->id] ?? null),
                'created_at' => $this->date($row->created_at ?? null) ?? now(),
            ]);

            if ($client->wasRecentlyCreated) {
                $this->applyPassword($client, $row->password ?? null);
            }

            $credit = $credits->get($row->id, collect())->where('currency_code', $client->currency)->sum(fn (stdClass $credit): int => $this->money($credit->amount));
            $this->syncCredit($client, max(0, (int) $credit), (int) $row->id);
        }
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
                'description' => $this->text(strip_tags((string) ($row->description ?? ''))),
                'sort_order' => (int) ($row->sort ?? 0),
            ], ['slug' => $this->unique(ProductGroup::class, 'slug', Str::slug($this->text($row->slug ?? null) ?? $name) ?: 'group'), 'is_visible' => true]);
        }
    }

    /**
     * Products with their plans. Servers are set up in Nuvabill, so products start without a module.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importProducts(Collection $rows): void
    {
        $plans = collect($this->plans())->filter(fn (stdClass $plan): bool => str_ends_with((string) $plan->priceable_type, 'Product') && in_array($plan->priceable_id, $rows->pluck('id')->all()));
        $prices = $this->hasTable('prices') ? $this->db()->table('prices')->whereIn('plan_id', $plans->keys())->get()->groupBy('plan_id') : collect();

        foreach ($rows as $row) {
            $groupId = $this->localId('product_group', $row->category_id ?? 0);

            if ($groupId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $name = $this->text($row->name ?? null) ?? 'Product '.$row->id;

            $product = $this->upsert('product', (int) $row->id, Product::class, [
                'product_group_id' => $groupId,
                'name' => $name,
                'description' => $this->text($row->description ?? null),
                'stock' => is_numeric($row->stock ?? null) ? max(0, (int) $row->stock) : null,
                'sort_order' => (int) ($row->sort ?? 0),
            ], [
                'type' => ProductType::Other->value,
                'slug' => $this->unique(Product::class, 'slug', Str::slug($this->text($row->slug ?? null) ?? $name) ?: 'product'),
                'is_visible' => true,
                'requires_domain' => false,
                'auto_setup' => AutoSetup::OnPayment->value,
                'module_config' => [],
            ]);

            foreach ($plans->where('priceable_id', $row->id) as $plan) {
                if (($cycle = $this->planCycle($plan)) === null) {
                    continue;
                }

                foreach ($prices->get($plan->id, collect()) as $price) {
                    ProductPrice::query()->updateOrCreate(
                        ['product_id' => $product->id, 'currency' => strtoupper((string) $price->currency_code), 'billing_cycle' => $cycle->value],
                        ['price' => max(0, $this->money($price->price ?? 0)), 'setup_fee' => max(0, $this->money($price->setup_fee ?? 0))],
                    );
                }
            }
        }
    }

    /**
     * The Nuvabill billing cycle of a plan, or null for periods Nuvabill does not bill (hours, days, weeks).
     */
    private function planCycle(?stdClass $plan): ?BillingCycle
    {
        if ($plan === null) {
            return null;
        }

        if ($plan->type === 'free') {
            return BillingCycle::Free;
        }

        if ($plan->type === 'one-time') {
            return BillingCycle::OneTime;
        }

        $months = match ((string) $plan->billing_unit) {
            'month' => (int) $plan->billing_period,
            'year' => (int) $plan->billing_period * 12,
            default => 0,
        };

        return match ($months) {
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
        $properties = $this->properties('Service', $rows->pluck('id')->all());

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->user_id ?? 0);
            $productId = $this->localId('product', $row->product_id ?? 0);

            if ($clientId === null || $productId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $plan = $this->plans()[(int) ($row->plan_id ?? 0)] ?? null;
            $cycle = $this->planCycle($plan);
            $status = match ((string) ($row->status ?? '')) {
                'active' => ServiceStatus::Active,
                'suspended' => ServiceStatus::Suspended,
                'cancelled', 'canceled' => ServiceStatus::Cancelled,
                default => ServiceStatus::Pending,
            };

            // Billed by the hour, day or week: as a one-time service it would never be billed again.
            if ($cycle === null && $plan !== null && $status !== ServiceStatus::Cancelled) {
                $this->counts['skipped']++;
                $this->errors[] = ['id' => (int) $row->id, 'error' => __('Billed every :period, which Nuvabill does not have. Add this service by hand.', ['period' => $plan->billing_period.' '.$plan->billing_unit])];

                continue;
            }

            $cycle ??= (float) ($row->price ?? 0) > 0 ? BillingCycle::OneTime : BillingCycle::Free;
            $amount = $this->money($row->price ?? 0) * max(1, (int) ($row->quantity ?? 1));
            $domain = strtolower((string) $this->text($properties[$row->id]['domain'] ?? $properties[$row->id]['hostname'] ?? null));

            $service = $this->upsert('service', (int) $row->id, Service::class, [
                'product_id' => $productId,
                'domain' => preg_match('/^[a-z0-9-]+(\.[a-z0-9-]+)+$/', $domain) ? $domain : null,
                'status' => $status->value,
                'billing_cycle' => $cycle->value,
                'first_payment_amount' => $amount,
                'recurring_amount' => $cycle->isRecurring() ? $amount : 0,
                'registration_date' => $this->date($row->created_at ?? null) ?? today()->toDateString(),
                'next_due_date' => $cycle->isRecurring() ? $this->date($row->expires_at ?? null) : null,
            ], [
                'client_id' => $clientId,
                'currency' => strtoupper((string) ($row->currency_code ?? '')) ?: $this->clientCurrency($clientId),
            ]);

            $service->suspended_at = $status === ServiceStatus::Suspended ? ($service->suspended_at ?? now()) : null;
            $service->save();
        }
    }

    /**
     * Invoices with their lines. Lines for services are linked to them.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importInvoices(Collection $rows): void
    {
        $ids = $rows->pluck('id')->all();
        $items = $this->db()->table('invoice_items')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
        $payments = $this->rows('transactions')->whereIn('invoice_id', $ids)->orderBy('id')->get()->groupBy('invoice_id');
        // Credit already used on an invoice counts as paid: the imported wallet no longer holds it.
        $applied = $this->hasTable('invoice_transactions')
            ? $this->db()->table('invoice_transactions')->whereIn('invoice_id', $ids)
                ->when($this->hasColumn('invoice_transactions', 'status'), fn (Builder $query) => $query->where('status', 'succeeded'))
                ->get(['invoice_id', 'amount'])->groupBy('invoice_id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->user_id ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $status = match ((string) ($row->status ?? '')) {
                'paid' => InvoiceStatus::Paid,
                'cancelled', 'canceled' => InvoiceStatus::Cancelled,
                default => InvoiceStatus::Unpaid,
            };

            $lines = $items->get($row->id, collect());
            $paid = $payments->get($row->id, collect());
            $total = (int) $lines->sum(fn (stdClass $item): int => $this->money($item->price ?? 0) * max(1, (int) ($item->quantity ?? 1)));
            $issued = $this->date($row->created_at ?? null) ?? today()->toDateString();
            $isNew = $this->localId('invoice', $row->id) === null;

            $invoice = $this->upsert('invoice', (int) $row->id, Invoice::class, [
                'status' => $status->value,
                'subtotal' => $total,
                'tax' => 0,
                'total' => $total,
                'amount_paid' => $status === InvoiceStatus::Paid ? $total : min($total, max(0, (int) $applied->get($row->id, collect())->sum(fn (stdClass $payment): int => $this->money($payment->amount ?? 0)))),
                'issued_at' => $issued,
                'due_at' => $this->date($row->due_at ?? null) ?? $issued,
                'paid_at' => $status === InvoiceStatus::Paid ? ($this->date($paid->last()?->created_at ?? null) ?? $this->date($row->updated_at ?? null) ?? $issued) : null,
                'payment_method' => $paid->isNotEmpty() ? $this->gatewaySlug($this->gatewayName($paid->last()->gateway_id ?? 0)) : null,
            ], [
                'client_id' => $clientId,
                'currency' => strtoupper((string) ($row->currency_code ?? '')) ?: $this->clientCurrency($clientId),
                'number' => $this->unique(Invoice::class, 'number', $this->text($row->number ?? null) ?? (string) $row->id),
            ]);

            if (! $isNew) {
                $this->freeBillingKeys($invoice);

                continue;
            }

            foreach ($lines as $item) {
                $service = str_ends_with((string) ($item->reference_type ?? ''), 'Service') ? Service::query()->find($this->localId('service', $item->reference_id ?? 0)) : null;
                $renewal = $service !== null && in_array($service->status, [ServiceStatus::Active, ServiceStatus::Suspended], true);

                $this->addInvoiceItem($invoice, InvoiceItem::TYPE_SERVICE, (string) ($item->description ?? '-'), $this->money($item->price ?? 0) * max(1, (int) ($item->quantity ?? 1)), $service, renewal: $renewal);
            }
        }
    }

    /**
     * Payments that went through. Payments made from credit are left out: the wallet already counts them.
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
            $reference = Str::limit($this->text($row->transaction_id ?? null) ?? 'paymenter-'.$row->id, 180, '');

            if ($this->localId('transaction', $row->id) === null && Transaction::query()->where('gateway', $gateway)->where('reference', $reference)->exists()) {
                $reference .= '-pm'.$row->id;
            }

            $this->upsert('transaction', (int) $row->id, Transaction::class, [
                'invoice_id' => $invoice->id,
                'type' => $amount < 0 ? 'refund' : 'payment',
                'amount' => abs($amount),
                'fee' => max(0, $this->money($row->fee ?? 0)),
                'paid_at' => $this->date($row->created_at ?? null) ?? now(),
            ], [
                'client_id' => $invoice->client_id,
                'gateway' => $gateway,
                'reference' => $reference,
                'currency' => $invoice->currency,
                'meta' => ['paymenter_id' => (int) $row->id],
            ]);
        }
    }

    /**
     * Tickets with every message. Departments are matched by name, or made.
     *
     * @param  Collection<int, stdClass>  $rows
     */
    protected function importTickets(Collection $rows): void
    {
        $messages = $this->hasTable('ticket_messages')
            ? $this->db()->table('ticket_messages')->whereIn('ticket_id', $rows->pluck('id'))->orderBy('id')->get()->groupBy('ticket_id')
            : collect();

        foreach ($rows as $row) {
            $clientId = $this->localId('client', $row->user_id ?? 0);

            if ($clientId === null) {
                $this->counts['skipped']++;

                continue;
            }

            $thread = $messages->get($row->id, collect());
            $opened = $this->date($row->created_at ?? null) ?? now()->toDateTimeString();
            $lastReply = $this->date($thread->last()?->created_at ?? null) ?? $this->date($row->updated_at ?? null) ?? $opened;
            $status = match ((string) ($row->status ?? '')) {
                'closed' => TicketStatus::Closed,
                'replied' => TicketStatus::Answered,
                default => TicketStatus::Open,
            };

            $ticket = $this->upsert('ticket', (int) $row->id, Ticket::class, [
                'subject' => Str::limit($this->text($row->subject ?? null) ?? __('(no subject)'), 250),
                'status' => $status->value,
                'priority' => (match (strtolower((string) ($row->priority ?? ''))) {
                    'low' => TicketPriority::Low,
                    'high' => TicketPriority::High,
                    default => TicketPriority::Medium,
                })->value,
                'last_reply_at' => $lastReply,
                'closed_at' => $status === TicketStatus::Closed ? $lastReply : null,
            ], [
                'number' => $this->unique(Ticket::class, 'number', (string) $row->id),
                'client_id' => $clientId,
                'ticket_department_id' => $this->departmentNamed($row->department ?? null),
                'service_id' => $this->localId('service', $row->service_id ?? 0),
                'created_at' => $opened,
            ]);

            foreach ($thread as $message) {
                if ($this->localId('ticket_reply', $message->id) !== null) {
                    continue;
                }

                $staffId = (int) $message->user_id !== (int) $row->user_id ? $this->localId('admin', $message->user_id) : null;
                $author = $staffId !== null ? ['admin', $staffId] : ['client', $this->localId('client', $message->user_id) ?? $clientId];

                $reply = $this->addTicketReply($ticket, $author, (string) ($message->message ?? ''), $this->date($message->created_at ?? null) ?? $lastReply);
                $this->remember('ticket_reply', (int) $message->id, $reply->id);
            }
        }
    }

    public function preflight(): Preflight
    {
        $report = new Preflight(self::name(), $this->version());
        $this->addSteps($report);

        $users = $this->db()->table('users')->get(['email', 'password']);
        $this->checkEmails($report, $users->pluck('email'));
        $this->checkPasswords($report, $users->pluck('password'));

        $extensions = $this->hasTable('extensions') ? $this->db()->table('extensions')->get(['name', 'extension', 'type']) : collect();
        $servers = $extensions->where('type', 'server');
        $report->problem(Preflight::WARNING, $servers->count(), 'Server connections, not imported: :count. Add your servers in Nuvabill, then choose them on the products.', examples: $servers->map(fn (stdClass $row): string => (string) ($this->text($row->name) ?? $row->extension))->all());

        $unsupported = collect($this->plans())->filter(fn (stdClass $plan): bool => str_ends_with((string) $plan->priceable_type, 'Product') && $this->planCycle($plan) === null);
        $report->problem(Preflight::WARNING, $unsupported->count(), 'Prices with a billing period Nuvabill does not have: :count. They are not imported.', examples: $unsupported->map(fn (stdClass $plan): string => $plan->billing_period.' '.$plan->billing_unit)->unique()->values()->all());
        $report->problem(Preflight::WARNING, $unsupported->isEmpty() ? 0 : $this->db()->table('services')->whereIn('plan_id', $unsupported->keys()->all())->whereNotIn('status', ['cancelled', 'canceled'])->count(), 'Services with a billing period Nuvabill does not have: :count. They are not imported; add them by hand.');

        $suspendDays = (int) setting('automation.suspend_days');

        if ($suspendDays > 0 && $this->hasTable('invoice_items')) {
            $overdue = $this->db()->table('services')
                ->where('status', 'active')
                ->whereIn('id', fn (Builder $query) => $query->select('reference_id')->from('invoice_items')
                    ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                    ->where('invoice_items.reference_type', 'like', '%Service')
                    ->where('invoices.status', 'pending')
                    ->where('invoices.due_at', '<=', today()->subDays($suspendDays)->toDateString()))
                ->count();

            $report->problem(Preflight::WARNING, $overdue, 'Active services with an invoice more than :days days overdue: :count. Nuvabill\'s first nightly run suspends them.', ['days' => $suspendDays]);
        }

        if ($this->hasTable('properties')) {
            $report->problem(Preflight::WARNING, $this->db()->table('properties')->where(fn (Builder $query) => $query->where('model_type', 'not like', '%User')->orWhereNotIn('key', array_keys(self::USER_PROPERTIES)))->whereNotIn('key', ['domain', 'hostname'])->count(), 'Custom field values, not imported: :count.');
        }

        $this->checkCurrencies($report, $this->hasTable('currencies') ? $this->db()->table('currencies')->pluck('code') : collect());
        $this->checkGateways($report, $extensions->where('type', 'gateway')->pluck('extension'));

        if ($this->hasTable('credits')) {
            $report->problem(Preflight::INFO, $this->db()->table('credits')->where('amount', '>', 0)->distinct()->count('user_id'), 'Clients with credit: :count. It becomes their Nuvabill wallet balance.');
        }

        $this->automationAdvice($report);

        return $report;
    }

    /**
     * Property values by model ID and key, for "User" or "Service".
     *
     * @param  list<int|string>  $ids
     * @return array<int, array<string, string>>
     */
    private function properties(string $model, array $ids): array
    {
        if (! $this->hasTable('properties')) {
            return [];
        }

        $values = [];

        foreach ($this->db()->table('properties')->where('model_type', 'like', '%'.$model)->whereIn('model_id', $ids)->get(['model_id', 'key', 'value']) as $property) {
            $values[(int) $property->model_id][strtolower((string) $property->key)] = (string) $property->value;
        }

        return $values;
    }

    protected function sourceClients(array $ids): ?array
    {
        return $this->keyClients($this->db()->table('users')->whereIn('id', $ids)->get(['id', 'email', 'created_at as created']));
    }

    /**
     * @return array<int, stdClass>
     */
    private function plans(): array
    {
        return $this->plans ??= $this->hasTable('plans') ? $this->db()->table('plans')->get()->keyBy('id')->all() : [];
    }

    private function gatewayName(mixed $gatewayId): ?string
    {
        $this->gateways ??= $this->hasTable('extensions') ? $this->db()->table('extensions')->pluck('extension', 'id')->all() : [];

        return $this->text($this->gateways[(int) $gatewayId] ?? null);
    }

    private function currencyCode(mixed $code): string
    {
        $code = strtoupper(trim((string) $code));

        if (preg_match('/^[A-Z]{3}$/', $code)) {
            return $code;
        }

        return $this->defaultCurrency ??= strtoupper((string) ($this->hasTable('currencies') ? $this->db()->table('currencies')->value('code') : null) ?: setting('billing.currency'));
    }

    /**
     * An ISO country code from a code or a country name.
     */
    private function countryCode(?string $value): ?string
    {
        $value = trim((string) $value);

        if (preg_match('/^[A-Za-z]{2}$/', $value)) {
            return strtoupper($value);
        }

        $code = array_search(mb_strtolower($value), array_map('mb_strtolower', Countries::all()), true);

        return $value !== '' && is_string($code) ? $code : null;
    }
}
