<?php

namespace Tests\Feature\Import;

use App\Billing\InvoicePaidHandler;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketPriority;
use App\Enums\TicketStatus;
use App\Import\Blesta\BlestaImporter;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Domain;
use App\Models\ImportMapping;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TldPrice;
use App\Models\Transaction;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BlestaImportTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'blesta_test';

    private const SYSTEM_KEY = 'blesta-system-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        $this->createTables();
        $this->seedBlesta();
    }

    public function test_it_imports_a_blesta_database(): void
    {
        $this->runImport();

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertSame('Raz Studio', $raz->company_name);
        $this->assertSame('+964 750 123 4567', $raz->phone);
        $this->assertSame('IQ', $raz->country);
        $this->assertSame('USD', $raz->currency);
        $this->assertSame(500, $raz->credit, 'Money paid but not applied to an invoice');
        $this->assertStringStartsWith('hmac-bcrypt:blesta:', (string) $raz->legacy_password);

        $product = Product::query()->where('name', 'Starter Hosting')->firstOrFail();
        $this->assertSame('cpanel', $product->server_module);
        $this->assertSame(['package' => 'starter'], $product->module_config);
        $this->assertSame(500, $product->prices()->where('billing_cycle', 'monthly')->value('price'));
        $this->assertFalse($product->prices()->where('billing_cycle', 'weekly')->exists());
        $this->assertFalse(Product::query()->where('name', 'Domain .com')->exists(), 'Domain packages are not products');

        $server = Server::query()->firstOrFail();
        $this->assertSame('web1.example.com', $server->hostname);
        $this->assertSame(['ns1.example.com', 'ns2.example.com'], $server->nameservers);
        $this->assertNull($server->password, 'Encrypted values stay behind');

        $service = Service::query()->firstOrFail();
        $this->assertSame('razstudio.com', $service->domain);
        $this->assertSame('razstud', $service->username);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(BillingCycle::Monthly, $service->billing_cycle);
        $this->assertSame('2026-10-01', $service->next_due_date->toDateString());

        $domain = Domain::query()->where('name', 'razstudio.com')->firstOrFail();
        $this->assertSame('resellerclub', $domain->registrar);
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame(1299, TldPrice::forTld('com', 'USD')->register_price);
        $this->assertSame(1499, TldPrice::forTld('com', 'USD')->renew_price);

        $unpaid = Invoice::query()->where('number', 'INV-1501')->firstOrFail();
        $this->assertSame(InvoiceStatus::Unpaid, $unpaid->status);
        $this->assertSame(550, $unpaid->total);
        $this->assertSame(50, $unpaid->tax);
        $this->assertSame(RenewalGenerator::billingKey('service', $service->id, $service->next_due_date), $unpaid->items()->firstOrFail()->billing_key);
        $this->assertSame(InvoiceStatus::Paid, Invoice::query()->where('number', 'INV-1500')->firstOrFail()->status);

        $payment = Transaction::query()->where('reference', 'pp-1')->firstOrFail();
        $this->assertSame('paypal', $payment->gateway);
        $this->assertSame(Invoice::query()->where('number', 'INV-1500')->value('id'), $payment->invoice_id);

        $ticket = Ticket::query()->where('number', '4821')->firstOrFail();
        $this->assertSame(TicketStatus::Answered, $ticket->status);
        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertSame(['Where is my site?', 'Fixed now.'], $ticket->replies->pluck('message')->all(), 'Staff notes stay behind');
        $this->assertFalse(Admin::query()->where('email', 'lana@example.com')->value('is_active'));
    }

    public function test_blesta_passwords_work_with_the_system_key(): void
    {
        $this->runImport();
        app(Settings::class)->set('import.password_keys', ['blesta' => self::SYSTEM_KEY]);

        $this->post(route('client.login'), ['email' => 'raz@example.com', 'password' => 'wrong-one'])->assertSessionHasErrors('email');
        $this->post(route('client.login'), ['email' => 'raz@example.com', 'password' => 'raz-password-1'])->assertRedirect(route('client.dashboard'));

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertNull($raz->legacy_password);
        $this->assertTrue(Hash::check('raz-password-1', $raz->password));
    }

    public function test_running_it_again_makes_no_copies(): void
    {
        $this->runImport();
        DB::connection(self::CONNECTION)->table('services')->where('id', 30)->update(['status' => 'suspended', 'date_suspended' => '2026-10-05 00:00:00']);

        $this->runImport();

        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, Service::query()->count());
        $this->assertSame(1, Domain::query()->count());
        $this->assertSame(ServiceStatus::Suspended, Service::query()->first()->status);
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(500, Client::query()->first()->credit);
    }

    public function test_the_dry_run_warns_about_the_missing_key(): void
    {
        $texts = collect((new BlestaImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');

        $this->assertSame(1, $texts['Client passwords that need the Blesta key: :count. Add the key, or clients choose a new password on the sign-in page.']['params']['count']);
        $this->assertSame(1, $texts['Tickets from guests without a client account: :count. They are skipped.']['params']['count']);
        $withKey = collect((new BlestaImporter(self::CONNECTION, self::SYSTEM_KEY))->preflight()->toArray()['problems'])->pluck('text');
        $this->assertNotContains('Client passwords that need the Blesta key: :count. Add the key, or clients choose a new password on the sign-in page.', $withKey);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_running_it_again_keeps_payments_made_in_nuvabill(): void
    {
        Mail::fake();
        $this->runImport();

        $invoice = Invoice::query()->where('number', 'INV-1501')->firstOrFail();
        app(PaymentRecorder::class)->record($invoice, 550, 'banktransfer', 'nb-1');
        $service = Service::query()->firstOrFail();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('2026-11-01', $service->fresh()->next_due_date->toDateString());

        // Blesta never saw that payment.
        $this->runImport();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(550, $invoice->amount_paid);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('2026-11-01', $service->fresh()->next_due_date->toDateString(), 'The renewal is not undone');
    }

    public function test_a_draft_finalized_later_gets_its_number_and_lines(): void
    {
        $db = DB::connection(self::CONNECTION);
        $db->table('invoices')->insert(['id' => 3, 'id_format' => 'DRAFT-{num}', 'id_value' => 5, 'client_id' => 1, 'date_billed' => '2026-10-20 00:00:00', 'date_due' => '2026-11-01 00:00:00', 'date_closed' => null, 'status' => 'draft', 'currency' => 'USD', 'subtotal' => '5.0000', 'total' => '5.0000', 'paid' => '0.0000', 'note_public' => '']);
        $db->table('invoice_lines')->insert(['id' => 4, 'invoice_id' => 3, 'service_id' => 30, 'description' => 'Starter Hosting', 'qty' => '1', 'amount' => '5.0000', 'order' => 0]);

        $this->runImport();

        $invoice = Invoice::query()->where('status', InvoiceStatus::Draft)->firstOrFail();
        $this->assertNull($invoice->number);

        $db->table('invoices')->where('id', 3)->update(['status' => 'active', 'id_format' => 'INV-{num}', 'id_value' => 1502, 'subtotal' => '7.0000', 'total' => '7.0000']);
        $db->table('invoice_lines')->where('id', 4)->update(['amount' => '7.0000']);
        // It bills the period after INV-1501's.
        $db->table('services')->where('id', 30)->update(['date_renews' => '2026-11-01 00:00:00']);

        $this->runImport();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('INV-1502', $invoice->number);
        $this->assertSame(700, (int) $invoice->items()->sum('amount'));
        $item = $invoice->items()->firstOrFail();
        $this->assertNotNull($item->period_start, 'Paying it renews the service');
        $this->assertNotNull($item->billing_key);
    }

    public function test_the_dry_run_counts_credit_in_few_queries_and_in_each_clients_currency(): void
    {
        $db = DB::connection(self::CONNECTION);

        foreach (range(100, 139) as $id) {
            $db->table('clients')->insert(['id' => $id, 'user_id' => null, 'status' => 'active']);
            $db->table('transactions')->insert(['client_id' => $id, 'amount' => '3.0000', 'currency' => 'USD', 'type' => 'other', 'gateway_id' => null, 'transaction_id' => "bank-{$id}", 'status' => 'approved', 'date_added' => '2026-09-22 12:00:00']);
        }

        $db->table('clients')->insert(['id' => 200, 'user_id' => null, 'status' => 'active']);
        $db->table('client_settings')->insert(['client_id' => 200, 'key' => 'default_currency', 'value' => 'EUR', 'encrypted' => 0]);
        $db->table('transactions')->insert(['client_id' => 200, 'amount' => '4.0000', 'currency' => 'EUR', 'type' => 'other', 'gateway_id' => null, 'transaction_id' => 'bank-eur', 'status' => 'approved', 'date_added' => '2026-09-22 12:00:00']);

        $queries = 0;
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if ($query->connectionName === self::CONNECTION && str_contains($query->sql, 'transactions')) {
                $queries++;
            }
        });

        $texts = collect((new BlestaImporter(self::CONNECTION, self::SYSTEM_KEY))->preflight()->toArray()['problems'])->keyBy('text');

        $this->assertSame(42, $texts['Clients with credit: :count. It becomes their Nuvabill wallet balance.']['params']['count'], 'Raz, 40 clients in USD and one in EUR');
        $this->assertLessThan(5, $queries, 'Not two queries per client');
    }

    public function test_a_service_suspended_in_nuvabill_stays_suspended_on_a_rerun(): void
    {
        $this->runImport();
        $service = Service::query()->firstOrFail();
        $service->forceFill(['status' => ServiceStatus::Suspended, 'suspended_at' => '2026-10-03 08:00:00', 'suspension_reason' => InvoicePaidHandler::OVERDUE_REASON])->save();
        Activity::log('service.suspended', 'Suspended', $service);

        // Blesta still shows it active.
        $this->runImport();

        $service->refresh();
        $this->assertSame(ServiceStatus::Suspended, $service->status);
        $this->assertSame('2026-10-03 08:00:00', $service->suspended_at->toDateTimeString());
        $this->assertSame(InvoicePaidHandler::OVERDUE_REASON, $service->suspension_reason);
    }

    public function test_clients_that_share_an_email_are_imported_once_with_the_right_advice(): void
    {
        $db = DB::connection(self::CONNECTION);
        $db->table('clients')->insert(['id' => 2, 'user_id' => null, 'status' => 'active']);
        $db->table('contacts')->insert(['id' => 2, 'client_id' => 2, 'contact_type' => 'primary', 'first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'RAZ@example.com', 'country' => 'IQ', 'date_added' => '2024-03-04 10:00:00']);

        $texts = collect((new BlestaImporter(self::CONNECTION, self::SYSTEM_KEY))->preflight()->toArray()['problems'])->keyBy('text');
        $shared = $texts['Clients with the same email as another :system client: :count. Only the first one is imported; give the others their own email in :system.'];
        $this->assertSame('warning', $shared['level']);
        $this->assertSame(1, $shared['params']['count']);
        $this->assertSame(['raz@example.com'], $shared['examples']);

        $errors = $this->runImport(allowErrors: true);

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertSame(1, Client::query()->count());
        $this->assertSame('Raz', $raz->first_name);
        $error = (string) collect($errors)->where('step', 'clients')->firstWhere('id', 2)['error'];
        $this->assertStringContainsString("Another Blesta client with this email was imported as Nuvabill client #{$raz->id}", $error);
        $this->assertStringNotContainsString('delete it', $error, 'The account belongs to the other Blesta client');
    }

    public function test_a_client_an_earlier_version_merged_into_another_with_the_same_email_is_never_changed(): void
    {
        $this->runImport();
        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();

        // An earlier version joined a second Blesta client with the same email to Raz's account.
        $db = DB::connection(self::CONNECTION);
        $db->table('clients')->insert(['id' => 2, 'user_id' => null, 'status' => 'inactive']);
        $db->table('contacts')->insert(['id' => 2, 'client_id' => 2, 'contact_type' => 'primary', 'first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'raz@example.com', 'country' => 'IQ', 'date_added' => '2024-03-04 10:00:00']);
        ImportMapping::query()->create(['source' => 'blesta', 'entity' => 'client', 'source_id' => 2, 'local_id' => $raz->id]);

        $this->runImport();
        $this->runImport();

        $raz->refresh();
        $this->assertSame('Raz', $raz->first_name, 'Not overwritten by the other Blesta client');
        $this->assertSame(ClientStatus::Active, $raz->status);
        $this->assertSame(500, $raz->credit, "Not set to the other client's balance");
        $this->assertTrue(ImportMapping::query()->where(['source' => 'blesta', 'entity' => 'client_link', 'source_id' => 2])->exists());
    }

    /**
     * @return list<array{step: string, id: int, error: string}> The rows that were skipped with a reason.
     */
    private function runImport(bool $allowErrors = false): array
    {
        $importer = new BlestaImporter(self::CONNECTION, self::SYSTEM_KEY);
        $errors = [];

        foreach (array_keys(BlestaImporter::STEPS) as $step) {
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 1);

                if (! $allowErrors) {
                    $this->assertSame([], $result['errors'], "Step {$step}");
                }

                foreach ($result['errors'] as $error) {
                    $errors[] = ['step' => $step] + $error;
                }

                $afterId = $result['last_id'];
            } while (! $result['done']);
        }

        return $errors;
    }

    private function seedBlesta(): void
    {
        $db = DB::connection(self::CONNECTION);

        $db->table('settings')->insert(['id' => 1, 'key' => 'database_version', 'value' => '5.12.0']);
        $db->table('company_settings')->insert(['id' => 1, 'company_id' => 1, 'key' => 'default_currency', 'value' => 'USD']);
        $db->table('currencies')->insert(['id' => 1, 'code' => 'USD']);
        $db->table('users')->insert(['id' => 1, 'username' => 'raz', 'password' => password_hash(hash_hmac('sha256', 'raz-password-1', self::SYSTEM_KEY), PASSWORD_BCRYPT, ['cost' => 4])]);
        $db->table('staff')->insert(['id' => 1, 'user_id' => 2, 'first_name' => 'Lana', 'last_name' => 'Staff', 'email' => 'lana@example.com', 'status' => 'active']);
        $db->table('clients')->insert(['id' => 1, 'user_id' => 1, 'status' => 'active']);
        $db->table('contacts')->insert(['id' => 1, 'client_id' => 1, 'contact_type' => 'primary', 'first_name' => 'Raz', 'last_name' => 'Las', 'company' => 'Raz Studio', 'email' => 'raz@example.com', 'address1' => '1 Main St', 'city' => 'Erbil', 'zip' => '44001', 'country' => 'IQ', 'date_added' => '2024-01-02 10:00:00']);
        $db->table('contact_numbers')->insert(['id' => 1, 'contact_id' => 1, 'number' => '+964 750 123 4567', 'type' => 'phone', 'location' => 'work']);

        $db->table('modules')->insert([['id' => 1, 'class' => 'cpanel'], ['id' => 2, 'class' => 'logicboxes'], ['id' => 3, 'class' => 'none']]);
        $db->table('module_rows')->insert([['id' => 1, 'module_id' => 1, 'status' => 'active'], ['id' => 2, 'module_id' => 2, 'status' => 'active']]);
        $db->table('module_row_meta')->insert([
            ['id' => 1, 'module_row_id' => 1, 'key' => 'server_name', 'value' => 'Web 1', 'serialized' => 0, 'encrypted' => 0],
            ['id' => 2, 'module_row_id' => 1, 'key' => 'host_name', 'value' => 'web1.example.com', 'serialized' => 0, 'encrypted' => 0],
            ['id' => 3, 'module_row_id' => 1, 'key' => 'user_name', 'value' => 'root', 'serialized' => 0, 'encrypted' => 0],
            ['id' => 4, 'module_row_id' => 1, 'key' => 'key', 'value' => 'ENCRYPTEDVALUE', 'serialized' => 0, 'encrypted' => 1],
            ['id' => 5, 'module_row_id' => 1, 'key' => 'name_servers', 'value' => serialize(['ns1.example.com', 'ns2.example.com']), 'serialized' => 1, 'encrypted' => 0],
            ['id' => 6, 'module_row_id' => 1, 'key' => 'account_limit', 'value' => '200', 'serialized' => 0, 'encrypted' => 0],
        ]);

        $db->table('package_groups')->insert([['id' => 1, 'type' => 'standard'], ['id' => 2, 'type' => 'standard']]);
        $db->table('package_group_names')->insert([['id' => 1, 'package_group_id' => 1, 'lang' => 'en_us', 'name' => 'Hosting'], ['id' => 2, 'package_group_id' => 2, 'lang' => 'en_us', 'name' => 'Domains']]);
        $db->table('packages')->insert([['id' => 3, 'module_id' => 1, 'qty' => null, 'status' => 'active'], ['id' => 4, 'module_id' => 2, 'qty' => null, 'status' => 'active']]);
        $db->table('package_names')->insert([['id' => 1, 'package_id' => 3, 'lang' => 'en_us', 'name' => 'Starter Hosting'], ['id' => 2, 'package_id' => 4, 'lang' => 'en_us', 'name' => 'Domain .com']]);
        $db->table('package_group')->insert([['id' => 1, 'package_id' => 3, 'package_group_id' => 1], ['id' => 2, 'package_id' => 4, 'package_group_id' => 2]]);
        $db->table('package_meta')->insert(['id' => 1, 'package_id' => 3, 'key' => 'package', 'value' => 'starter', 'serialized' => 0, 'encrypted' => 0]);
        $db->table('pricings')->insert([
            ['id' => 1, 'term' => 1, 'period' => 'month', 'price' => '5.0000', 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => '0.0000', 'currency' => 'USD'],
            ['id' => 2, 'term' => 1, 'period' => 'week', 'price' => '1.5000', 'price_renews' => null, 'price_transfer' => null, 'setup_fee' => '0.0000', 'currency' => 'USD'],
            ['id' => 3, 'term' => 1, 'period' => 'year', 'price' => '12.9900', 'price_renews' => '14.9900', 'price_transfer' => '12.9900', 'setup_fee' => '0.0000', 'currency' => 'USD'],
        ]);
        $db->table('package_pricing')->insert([['id' => 10, 'package_id' => 3, 'pricing_id' => 1], ['id' => 11, 'package_id' => 3, 'pricing_id' => 2], ['id' => 12, 'package_id' => 4, 'pricing_id' => 3]]);
        $db->table('domains_tlds')->insert(['id' => 1, 'tld' => '.com', 'package_id' => 4]);

        $db->table('services')->insert([
            ['id' => 30, 'pricing_id' => 10, 'client_id' => 1, 'module_row_id' => 1, 'qty' => 1, 'override_price' => null, 'override_currency' => null, 'status' => 'active', 'date_added' => '2025-10-01 10:00:00', 'date_renews' => '2026-10-01 00:00:00', 'date_suspended' => null],
            ['id' => 31, 'pricing_id' => 12, 'client_id' => 1, 'module_row_id' => 2, 'qty' => 1, 'override_price' => null, 'override_currency' => null, 'status' => 'active', 'date_added' => '2025-10-01 10:00:00', 'date_renews' => '2027-10-01 00:00:00', 'date_suspended' => null],
        ]);
        $db->table('service_fields')->insert([
            ['id' => 1, 'service_id' => 30, 'key' => 'cpanel_domain', 'value' => 'razstudio.com', 'serialized' => 0, 'encrypted' => 0],
            ['id' => 2, 'service_id' => 30, 'key' => 'cpanel_username', 'value' => 'razstud', 'serialized' => 0, 'encrypted' => 0],
            ['id' => 3, 'service_id' => 30, 'key' => 'cpanel_password', 'value' => 'ENCRYPTED', 'serialized' => 0, 'encrypted' => 1],
            ['id' => 4, 'service_id' => 31, 'key' => 'domain-name', 'value' => 'razstudio.com', 'serialized' => 0, 'encrypted' => 0],
        ]);

        $db->table('invoices')->insert([
            ['id' => 1, 'id_format' => 'INV-{num}', 'id_value' => 1500, 'client_id' => 1, 'date_billed' => '2025-10-01 00:00:00', 'date_due' => '2025-10-01 00:00:00', 'date_closed' => '2025-10-01 12:00:00', 'status' => 'active', 'currency' => 'USD', 'subtotal' => '17.9900', 'total' => '17.9900', 'paid' => '17.9900', 'note_public' => ''],
            ['id' => 2, 'id_format' => 'INV-{num}', 'id_value' => 1501, 'client_id' => 1, 'date_billed' => '2026-09-20 00:00:00', 'date_due' => '2026-10-01 00:00:00', 'date_closed' => null, 'status' => 'active', 'currency' => 'USD', 'subtotal' => '5.0000', 'total' => '5.5000', 'paid' => '0.0000', 'note_public' => ''],
        ]);
        $db->table('invoice_lines')->insert([
            ['id' => 1, 'invoice_id' => 1, 'service_id' => 30, 'description' => 'Starter Hosting', 'qty' => '1', 'amount' => '5.0000', 'order' => 0],
            ['id' => 2, 'invoice_id' => 1, 'service_id' => 31, 'description' => 'razstudio.com', 'qty' => '1', 'amount' => '12.9900', 'order' => 1],
            ['id' => 3, 'invoice_id' => 2, 'service_id' => 30, 'description' => 'Starter Hosting renewal', 'qty' => '1', 'amount' => '5.0000', 'order' => 0],
        ]);
        $db->table('gateways')->insert(['id' => 1, 'class' => 'paypal_payments_standard']);
        $db->table('transactions')->insert([
            ['id' => 1, 'client_id' => 1, 'amount' => '17.9900', 'currency' => 'USD', 'type' => 'other', 'gateway_id' => 1, 'transaction_id' => 'pp-1', 'status' => 'approved', 'date_added' => '2025-10-01 12:00:00'],
            ['id' => 2, 'client_id' => 1, 'amount' => '5.0000', 'currency' => 'USD', 'type' => 'other', 'gateway_id' => null, 'transaction_id' => 'bank-7', 'status' => 'approved', 'date_added' => '2026-09-22 12:00:00'],
            ['id' => 3, 'client_id' => 1, 'amount' => '9.0000', 'currency' => 'USD', 'type' => 'cc', 'gateway_id' => 1, 'transaction_id' => 'declined-1', 'status' => 'declined', 'date_added' => '2026-09-22 12:00:00'],
        ]);
        $db->table('transaction_applied')->insert(['id' => 1, 'transaction_id' => 1, 'invoice_id' => 1, 'amount' => '17.9900']);

        $db->table('support_departments')->insert(['id' => 1, 'name' => 'Technical', 'email' => 'tech@example.com', 'description' => '']);
        $db->table('support_tickets')->insert([
            ['id' => 7, 'code' => 4821, 'department_id' => 1, 'staff_id' => null, 'service_id' => 30, 'client_id' => 1, 'summary' => 'Site down', 'priority' => 'critical', 'status' => 'awaiting_reply', 'date_added' => '2026-09-20 08:00:00', 'date_updated' => '2026-09-20 09:00:00', 'date_closed' => null],
            ['id' => 8, 'code' => 4822, 'department_id' => 1, 'staff_id' => null, 'service_id' => null, 'client_id' => null, 'summary' => 'Pre-sales', 'priority' => 'low', 'status' => 'open', 'date_added' => '2026-09-21 08:00:00', 'date_updated' => '2026-09-21 08:00:00', 'date_closed' => null],
        ]);
        $db->table('support_replies')->insert([
            ['id' => 1, 'ticket_id' => 7, 'staff_id' => null, 'contact_id' => 1, 'type' => 'reply', 'details' => 'Where is my site?', 'date_added' => '2026-09-20 08:00:00'],
            ['id' => 2, 'ticket_id' => 7, 'staff_id' => 1, 'contact_id' => null, 'type' => 'note', 'details' => 'Check the disk.', 'date_added' => '2026-09-20 08:30:00'],
            ['id' => 3, 'ticket_id' => 7, 'staff_id' => 1, 'contact_id' => null, 'type' => 'reply', 'details' => 'Fixed now.', 'date_added' => '2026-09-20 09:00:00'],
        ]);
    }

    private function createTables(): void
    {
        $columns = [
            'settings' => ['key', 'value'],
            'company_settings' => ['company_id', 'key', 'value'],
            'currencies' => ['code'],
            'users' => ['username', 'password'],
            'staff' => ['user_id', 'first_name', 'last_name', 'email', 'status'],
            'clients' => ['user_id', 'status'],
            'contacts' => ['client_id', 'contact_type', 'first_name', 'last_name', 'company', 'email', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'date_added'],
            'contact_numbers' => ['contact_id', 'number', 'type', 'location'],
            'client_settings' => ['client_id', 'key', 'value', 'encrypted'],
            'modules' => ['class'],
            'module_rows' => ['module_id', 'status'],
            'module_row_meta' => ['module_row_id', 'key', 'value', 'serialized', 'encrypted'],
            'package_groups' => ['type'],
            'package_group_names' => ['package_group_id', 'lang', 'name'],
            'packages' => ['module_id', 'qty', 'status'],
            'package_names' => ['package_id', 'lang', 'name'],
            'package_descriptions' => ['package_id', 'lang', 'text'],
            'package_group' => ['package_id', 'package_group_id'],
            'package_meta' => ['package_id', 'key', 'value', 'serialized', 'encrypted'],
            'pricings' => ['term', 'period', 'price', 'price_renews', 'price_transfer', 'setup_fee', 'currency'],
            'package_pricing' => ['package_id', 'pricing_id'],
            'domains_tlds' => ['tld', 'package_id'],
            'services' => ['pricing_id', 'client_id', 'module_row_id', 'qty', 'override_price', 'override_currency', 'status', 'date_added', 'date_renews', 'date_suspended'],
            'service_fields' => ['service_id', 'key', 'value', 'serialized', 'encrypted'],
            'invoices' => ['id_format', 'id_value', 'client_id', 'date_billed', 'date_due', 'date_closed', 'status', 'currency', 'subtotal', 'total', 'paid', 'note_public'],
            'invoice_lines' => ['invoice_id', 'service_id', 'description', 'qty', 'amount', 'order'],
            'gateways' => ['class'],
            'transactions' => ['client_id', 'amount', 'currency', 'type', 'gateway_id', 'transaction_id', 'status', 'date_added'],
            'transaction_applied' => ['transaction_id', 'invoice_id', 'amount'],
            'support_departments' => ['name', 'email', 'description'],
            'support_tickets' => ['code', 'department_id', 'staff_id', 'service_id', 'client_id', 'summary', 'priority', 'status', 'date_added', 'date_updated', 'date_closed'],
            'support_replies' => ['ticket_id', 'staff_id', 'contact_id', 'type', 'details', 'date_added'],
        ];

        foreach ($columns as $table => $names) {
            Schema::connection(self::CONNECTION)->create($table, function (Blueprint $blueprint) use ($names): void {
                $blueprint->id();

                foreach ($names as $name) {
                    $blueprint->string($name)->nullable();
                }
            });
        }
    }
}
