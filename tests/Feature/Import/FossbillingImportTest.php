<?php

namespace Tests\Feature\Import;

use App\Billing\RenewalGenerator;
use App\Enums\BillingCycle;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Import\Fossbilling\FossbillingImporter;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TldPrice;
use App\Models\Transaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FossbillingImportTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'fossbilling_test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        $this->createTables();
        $this->seedFossbilling();
    }

    public function test_it_imports_a_fossbilling_database(): void
    {
        $this->runImport();

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertSame('Raz & Co', $raz->company_name);
        $this->assertSame('+964 7501234567', $raz->phone);
        $this->assertSame('USD', $raz->currency);
        $this->assertSame(1500, $raz->credit, 'The balance entries add up to the wallet');
        $this->assertTrue(Hash::check('raz-password-1', $raz->password));
        $this->assertSame(1, Client::query()->count(), 'The client without an email is skipped');

        $product = Product::query()->where('name', 'Starter Hosting')->firstOrFail();
        $this->assertSame('cpanel', $product->server_module);
        $this->assertSame(['package' => 'starter'], $product->module_config);
        $this->assertSame(500, $product->prices()->where('billing_cycle', 'monthly')->value('price'));
        $this->assertSame(5000, $product->prices()->where('billing_cycle', 'annually')->value('price'));
        $this->assertFalse($product->prices()->where('billing_cycle', 'quarterly')->exists(), 'Periods switched off are not offered');

        $this->assertFalse(Server::query()->firstOrFail()->is_active);
        $this->assertSame('root-secret', Server::query()->firstOrFail()->password);

        $service = Service::query()->firstOrFail();
        $this->assertSame('razstudio.com', $service->domain);
        $this->assertSame('razstud', $service->username);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(BillingCycle::Monthly, $service->billing_cycle);
        $this->assertSame('2026-10-01', $service->next_due_date->toDateString());

        $this->assertSame(1299, TldPrice::forTld('com', 'USD')->register_price);
        $domain = Domain::query()->where('name', 'razstudio.com')->firstOrFail();
        $this->assertSame('resellerclub', $domain->registrar);
        $this->assertSame(DomainStatus::Active, $domain->status);

        $unpaid = Invoice::query()->where('number', 'INV00002')->firstOrFail();
        $this->assertSame(InvoiceStatus::Unpaid, $unpaid->status);
        $this->assertSame(550, $unpaid->total, '10% tax on the taxed line');
        $line = $unpaid->items()->firstOrFail();
        $this->assertSame($service->id, $line->service_id);
        $this->assertSame(RenewalGenerator::billingKey('service', $service->id, $service->next_due_date), $line->billing_key, 'An unpaid renewal is for the next due date');

        $this->assertSame(1, Transaction::query()->where('reference', 'txn-1')->where('gateway', 'paypal')->count());
        $this->assertSame(0, Transaction::query()->where('reference', 'txn-failed')->count(), 'Failed payments are left out');

        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame(TicketStatus::Answered, $ticket->status);
        $this->assertSame(['Where is my site?', 'Fixed & working now.'], $ticket->replies->pluck('message')->all());
        $this->assertSame('admin', $ticket->replies->last()->author_type);
        $this->assertFalse(Admin::query()->where('email', 'lana@example.com')->value('is_active'));
    }

    public function test_running_it_again_updates_without_copies(): void
    {
        $this->runImport();
        $db = DB::connection(self::CONNECTION);
        $db->table('client_order')->where('id', 10)->update(['status' => 'suspended', 'reason' => 'Overdue']);
        $db->table('invoice')->where('id', 2)->update(['status' => 'paid', 'paid_at' => '2026-10-02 10:00:00', 'nr' => '2']);
        $db->table('support_ticket_message')->insert(['id' => 3, 'support_ticket_id' => 7, 'client_id' => 1, 'content' => 'Thanks!', 'created_at' => '2026-09-21 09:00:00']);

        $this->runImport();

        $this->assertSame(1, Service::query()->count());
        $this->assertSame(ServiceStatus::Suspended, Service::query()->first()->status);
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(InvoiceStatus::Paid, Invoice::query()->where('number', 'INV00002')->first()->status);
        $this->assertSame(3, Ticket::query()->first()->replies()->count());
        $this->assertSame(1500, Client::query()->first()->credit);
    }

    public function test_the_dry_run_lists_what_would_not_come_across(): void
    {
        $preview = (new FossbillingImporter(self::CONNECTION))->preflight()->toArray();

        $this->assertSame(0, Client::query()->count());
        $this->assertSame(2, $preview['steps']['clients']['total']);
        $this->assertSame(1, $preview['steps']['services']['total']);
        $this->assertSame(1, $preview['steps']['domains']['total']);

        $texts = collect($preview['problems'])->pluck('params', 'text');
        $this->assertSame(1, $texts['Clients without a valid email address: :count. They are skipped.']['count']);
        $this->assertSame(1, $texts['Add-on products, not imported: :count.']['count']);
        $this->assertSame(1, $texts['Clients with credit: :count. It becomes their Nuvabill wallet balance.']['count']);
        $this->assertSame('FOSSBilling', $texts['Turn off automation in :system when you switch, and run the import one last time, so clients do not get two invoices.']['system']);
    }

    private function runImport(): void
    {
        $importer = new FossbillingImporter(self::CONNECTION);

        foreach (array_keys(FossbillingImporter::STEPS) as $step) {
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 1);
                $this->assertSame([], $result['errors'], "Step {$step}");
                $afterId = $result['last_id'];
            } while (! $result['done']);
        }
    }

    private function seedFossbilling(): void
    {
        $db = DB::connection(self::CONNECTION);

        $db->table('currency')->insert(['id' => 1, 'code' => 'USD', 'is_default' => 1]);
        $db->table('admin')->insert(['id' => 1, 'email' => 'lana@example.com', 'name' => 'Lana Staff', 'status' => 'active']);
        $db->table('client')->insert([
            ['id' => 1, 'email' => 'raz@example.com', 'pass' => password_hash('raz-password-1', PASSWORD_BCRYPT, ['cost' => 4]), 'status' => 'active', 'first_name' => 'Raz', 'last_name' => 'Las', 'company' => 'Raz &amp; Co', 'phone_cc' => '964', 'phone' => '7501234567', 'country' => 'IQ', 'currency' => 'USD', 'created_at' => '2024-01-02 10:00:00'],
            ['id' => 2, 'email' => '', 'pass' => '', 'status' => 'active', 'first_name' => 'No', 'last_name' => 'Mail', 'company' => '', 'phone_cc' => '', 'phone' => '', 'country' => '', 'currency' => 'USD', 'created_at' => '2024-01-02 10:00:00'],
        ]);
        $db->table('client_balance')->insert([
            ['id' => 1, 'client_id' => 1, 'amount' => '20.00', 'description' => 'Deposit'],
            ['id' => 2, 'client_id' => 1, 'amount' => '-5.00', 'description' => 'Invoice payment'],
        ]);

        $db->table('product_category')->insert(['id' => 1, 'title' => 'Hosting', 'description' => 'Fast hosting']);
        $db->table('product_payment')->insert(['id' => 1, 'type' => 'recurrent', 'm_price' => '5.00', 'm_setup_price' => '0.00', 'm_enabled' => 1, 'q_price' => '15.00', 'q_setup_price' => '0', 'q_enabled' => 0, 'a_price' => '50.00', 'a_setup_price' => '0', 'a_enabled' => 1, 'w_enabled' => 0, 'w_price' => '0', 'b_enabled' => 0, 'bia_enabled' => 0, 'tria_enabled' => 0]);
        $db->table('service_hosting_hp')->insert(['id' => 1, 'name' => 'starter']);
        $db->table('service_hosting_server')->insert(['id' => 1, 'name' => 'Web 1', 'ip' => '203.0.113.4', 'hostname' => 'web1.example.com', 'manager' => 'whm', 'username' => 'root', 'password' => 'root-secret', 'accesshash' => '', 'port' => '2087', 'secure' => 1, 'max_accounts' => 200, 'ns1' => 'ns1.example.com', 'ns2' => 'ns2.example.com']);
        $db->table('product')->insert([
            ['id' => 3, 'product_category_id' => 1, 'product_payment_id' => 1, 'title' => 'Starter Hosting', 'slug' => 'starter-hosting', 'description' => '', 'status' => 'enabled', 'hidden' => 0, 'is_addon' => 0, 'setup' => 'after_payment', 'type' => 'hosting', 'config' => '{"server_id":"1","hosting_plan_id":"1"}', 'stock_control' => 0, 'quantity_in_stock' => 0, 'priority' => 1],
            ['id' => 4, 'product_category_id' => 1, 'product_payment_id' => null, 'title' => 'Daily backups', 'slug' => 'daily-backups', 'description' => '', 'status' => 'enabled', 'hidden' => 0, 'is_addon' => 1, 'setup' => 'after_payment', 'type' => 'custom', 'config' => '', 'stock_control' => 0, 'quantity_in_stock' => 0, 'priority' => 2],
        ]);

        $db->table('service_hosting')->insert(['id' => 5, 'client_id' => 1, 'service_hosting_server_id' => 1, 'service_hosting_hp_id' => 1, 'sld' => 'razstudio', 'tld' => '.com', 'username' => 'razstud', 'pass' => 'razstud-pass']);
        $db->table('tld_registrar')->insert(['id' => 1, 'name' => 'ResellerClub', 'registrar' => 'Resellerclub']);
        $db->table('tld')->insert(['id' => 1, 'tld_registrar_id' => 1, 'tld' => '.com', 'price_registration' => '12.99', 'price_renew' => '14.99', 'price_transfer' => '12.99', 'allow_register' => 1, 'allow_transfer' => 1, 'require_transfer_code' => 1, 'active' => 1]);
        $db->table('service_domain')->insert(['id' => 6, 'client_id' => 1, 'tld_registrar_id' => 1, 'sld' => 'razstudio', 'tld' => '.com', 'period' => 1, 'action' => 'register', 'registered_at' => '2025-10-01 10:00:00', 'expires_at' => '2027-10-01 10:00:00']);
        $db->table('client_order')->insert([
            ['id' => 10, 'client_id' => 1, 'product_id' => 3, 'title' => 'Starter Hosting', 'currency' => 'USD', 'service_id' => 5, 'service_type' => 'hosting', 'period' => '1M', 'quantity' => 1, 'price' => '5.00', 'status' => 'active', 'reason' => '', 'expires_at' => '2026-10-01 10:00:00', 'activated_at' => '2025-10-01 10:00:00', 'created_at' => '2025-10-01 09:00:00'],
            ['id' => 11, 'client_id' => 1, 'product_id' => null, 'title' => 'Domain razstudio.com', 'currency' => 'USD', 'service_id' => 6, 'service_type' => 'domain', 'period' => '1Y', 'quantity' => 1, 'price' => '14.99', 'status' => 'active', 'reason' => '', 'expires_at' => '2027-10-01 10:00:00', 'activated_at' => '2025-10-01 10:00:00', 'created_at' => '2025-10-01 09:00:00'],
        ]);

        $db->table('invoice')->insert([
            ['id' => 1, 'client_id' => 1, 'serie' => 'INV', 'nr' => '1', 'currency' => 'USD', 'credit' => '0', 'status' => 'paid', 'taxrate' => '0', 'gateway_id' => 1, 'due_at' => '2025-10-01 10:00:00', 'paid_at' => '2025-10-01 12:00:00', 'created_at' => '2025-10-01 09:00:00', 'notes' => ''],
            ['id' => 2, 'client_id' => 1, 'serie' => 'INV', 'nr' => '2', 'currency' => 'USD', 'credit' => '0', 'status' => 'unpaid', 'taxrate' => '10', 'gateway_id' => 1, 'due_at' => '2026-10-01 10:00:00', 'paid_at' => null, 'created_at' => '2026-09-20 09:00:00', 'notes' => ''],
        ]);
        $db->table('invoice_item')->insert([
            ['id' => 1, 'invoice_id' => 1, 'type' => 'order', 'rel_id' => '10', 'task' => 'activate', 'title' => 'Starter Hosting', 'quantity' => 1, 'price' => '5.00', 'taxed' => 0],
            ['id' => 2, 'invoice_id' => 1, 'type' => 'order', 'rel_id' => '11', 'task' => 'activate', 'title' => 'Register razstudio.com', 'quantity' => 1, 'price' => '12.99', 'taxed' => 0],
            ['id' => 3, 'invoice_id' => 2, 'type' => 'order', 'rel_id' => '10', 'task' => 'renew', 'title' => 'Starter Hosting (renewal)', 'quantity' => 1, 'price' => '5.00', 'taxed' => 1],
        ]);
        $db->table('pay_gateway')->insert(['id' => 1, 'name' => 'PayPal', 'gateway' => 'PayPalEmail']);
        $db->table('transaction')->insert([
            ['id' => 1, 'invoice_id' => 1, 'gateway_id' => 1, 'txn_id' => 'txn-1', 'amount' => '17.99', 'currency' => 'USD', 'type' => 'payment', 'status' => 'processed', 'note' => '', 'created_at' => '2025-10-01 12:00:00'],
            ['id' => 2, 'invoice_id' => 2, 'gateway_id' => 1, 'txn_id' => 'txn-failed', 'amount' => '5.50', 'currency' => 'USD', 'type' => 'payment', 'status' => 'error', 'note' => '', 'created_at' => '2026-09-21 12:00:00'],
        ]);

        $db->table('support_helpdesk')->insert(['id' => 1, 'name' => 'Technical', 'email' => 'tech@example.com']);
        $db->table('support_ticket')->insert(['id' => 7, 'support_helpdesk_id' => 1, 'client_id' => 1, 'subject' => 'Site down', 'status' => 'open', 'priority' => 100, 'created_at' => '2026-09-20 08:00:00', 'updated_at' => '2026-09-20 09:00:00']);
        $db->table('support_ticket_message')->insert([
            ['id' => 1, 'support_ticket_id' => 7, 'client_id' => 1, 'admin_id' => null, 'content' => 'Where is my site?', 'attachment' => '', 'ip' => '198.51.100.7', 'created_at' => '2026-09-20 08:00:00'],
            ['id' => 2, 'support_ticket_id' => 7, 'client_id' => null, 'admin_id' => 1, 'content' => 'Fixed &amp; working now.', 'attachment' => '', 'ip' => '', 'created_at' => '2026-09-20 09:00:00'],
        ]);
    }

    private function createTables(): void
    {
        $columns = [
            'currency' => ['code', 'is_default'],
            'admin' => ['email', 'name', 'status'],
            'client' => ['email', 'pass', 'status', 'first_name', 'last_name', 'company', 'company_vat', 'phone_cc', 'phone', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'notes', 'tax_exempt', 'currency', 'created_at'],
            'client_balance' => ['client_id', 'amount', 'description'],
            'product_category' => ['title', 'description'],
            'product_payment' => ['type', 'once_price', 'once_setup_price', 'w_price', 'w_enabled', 'm_price', 'm_setup_price', 'm_enabled', 'q_price', 'q_setup_price', 'q_enabled', 'b_price', 'b_setup_price', 'b_enabled', 'a_price', 'a_setup_price', 'a_enabled', 'bia_price', 'bia_setup_price', 'bia_enabled', 'tria_price', 'tria_setup_price', 'tria_enabled'],
            'product' => ['product_category_id', 'product_payment_id', 'title', 'slug', 'description', 'status', 'hidden', 'is_addon', 'setup', 'type', 'config', 'stock_control', 'quantity_in_stock', 'priority'],
            'service_hosting_hp' => ['name'],
            'service_hosting_server' => ['name', 'ip', 'hostname', 'manager', 'username', 'password', 'accesshash', 'port', 'secure', 'max_accounts', 'ns1', 'ns2', 'ns3', 'ns4'],
            'service_hosting' => ['client_id', 'service_hosting_server_id', 'service_hosting_hp_id', 'sld', 'tld', 'username', 'pass'],
            'tld_registrar' => ['name', 'registrar'],
            'tld' => ['tld_registrar_id', 'tld', 'price_registration', 'price_renew', 'price_transfer', 'allow_register', 'allow_transfer', 'require_transfer_code', 'active'],
            'service_domain' => ['client_id', 'tld_registrar_id', 'sld', 'tld', 'period', 'action', 'registered_at', 'expires_at'],
            'client_order' => ['client_id', 'product_id', 'title', 'currency', 'service_id', 'service_type', 'period', 'quantity', 'price', 'status', 'reason', 'expires_at', 'activated_at', 'suspended_at', 'created_at'],
            'invoice' => ['client_id', 'serie', 'nr', 'currency', 'credit', 'status', 'taxrate', 'gateway_id', 'due_at', 'paid_at', 'created_at', 'notes'],
            'invoice_item' => ['invoice_id', 'type', 'rel_id', 'task', 'title', 'quantity', 'price', 'taxed'],
            'pay_gateway' => ['name', 'gateway'],
            'transaction' => ['invoice_id', 'gateway_id', 'txn_id', 'amount', 'currency', 'type', 'status', 'note', 'created_at'],
            'support_helpdesk' => ['name', 'email'],
            'support_ticket' => ['support_helpdesk_id', 'client_id', 'subject', 'status', 'priority', 'created_at', 'updated_at'],
            'support_ticket_message' => ['support_ticket_id', 'client_id', 'admin_id', 'content', 'attachment', 'ip', 'created_at'],
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
