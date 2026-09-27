<?php

namespace Tests\Feature\Import;

use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Import\Whmcs\WhmcsImporter;
use App\Jobs\ImportFromWhmcs;
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
use App\Support\Settings;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WhmcsImportTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'whmcs_test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        $this->createWhmcsTables();
    }

    public function test_it_imports_a_whmcs_database(): void
    {
        $this->seedWhmcs();

        $this->runImport();

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertSame('Raz & Co', $raz->company_name);
        $this->assertSame('IQ', $raz->country);
        $this->assertSame('IQD', $raz->currency);
        $this->assertSame(1250, $raz->credit);
        $this->assertTrue(Hash::check('raz-password-1', $raz->password), 'WHMCS 8 owner password works');

        $sam = Client::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('sam-password-1', $sam->password), 'WHMCS 7 password works');
        $this->assertSame(ClientStatus::Inactive, $sam->status);

        $product = Product::query()->where('name', 'Starter Hosting')->firstOrFail();
        $this->assertSame('cpanel', $product->server_module);
        $this->assertSame(['package' => 'starter'], $product->module_config);
        $this->assertSame(500, $product->prices()->where('currency', 'USD')->where('billing_cycle', 'monthly')->value('price'));
        $this->assertFalse($product->prices()->where('billing_cycle', 'quarterly')->exists(), 'Cycles priced -1 are not offered');

        $this->assertSame(1, Server::query()->count(), 'Unknown server types are skipped');
        $this->assertFalse(Server::query()->first()->is_active);

        $service = Service::query()->where('domain', 'razstudio.com')->firstOrFail();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(BillingCycle::Monthly, $service->billing_cycle);
        $this->assertSame('2026-10-01', $service->next_due_date->toDateString());

        $this->assertSame(1299, TldPrice::forTld('com', 'USD')->register_price);
        $this->assertSame(1499, TldPrice::forTld('com', 'USD')->renew_price);
        $domain = Domain::query()->where('name', 'razstudio.com')->firstOrFail();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('resellerclub', $domain->registrar);

        $unpaid = Invoice::query()->where('number', '1001')->firstOrFail();
        $this->assertSame(InvoiceStatus::Unpaid, $unpaid->status);
        $this->assertSame(500, $unpaid->total);
        $this->assertSame(200, $unpaid->amount_paid);
        $line = $unpaid->items()->firstOrFail();
        $this->assertSame($service->id, $line->service_id);
        $this->assertSame('2026-10-01', $line->period_start->toDateString(), 'Paying it renews the service');

        $this->assertSame(1, Transaction::query()->where('reference', 'txn-1')->count());

        $ticket = Ticket::query()->where('number', '482913')->firstOrFail();
        $this->assertSame(TicketStatus::Answered, $ticket->status);
        $this->assertSame(['Where is my site?', 'Fixed & working now.'], $ticket->replies->pluck('message')->all());
        $this->assertSame('Lana Staff', $ticket->replies->last()->authorName());
        $this->assertFalse(Admin::query()->where('email', 'lana@example.com')->value('is_active'));
    }

    public function test_running_it_again_updates_records_without_copies(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        $whmcs = DB::connection(self::CONNECTION);
        $whmcs->table('tblhosting')->where('id', 10)->update(['domainstatus' => 'Suspended', 'suspendreason' => 'Overdue']);
        $whmcs->table('tblinvoices')->where('id', 1001)->update(['status' => 'Paid', 'datepaid' => '2026-10-02 10:00:00']);
        $whmcs->table('tblticketreplies')->insert(['id' => 3, 'tid' => 7, 'userid' => 1, 'admin' => '', 'date' => '2026-09-21 09:00:00', 'message' => 'Thanks!']);

        $this->runImport();

        $this->assertSame(2, Client::query()->count());
        $this->assertSame(1, Service::query()->count());
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(1, Invoice::query()->where('number', '1001')->firstOrFail()->items()->count());
        $this->assertSame(ServiceStatus::Suspended, Service::query()->first()->status);
        $this->assertSame('Overdue', Service::query()->first()->suspension_reason);
        $this->assertSame(InvoiceStatus::Paid, Invoice::query()->where('number', '1001')->first()->status);
        $this->assertSame(3, Ticket::query()->first()->replies()->count());
    }

    public function test_staff_start_the_import_from_settings(): void
    {
        Queue::fake();
        $this->signInAdmin();

        $this->get(route('admin.settings.import.index'))->assertOk()->assertSee('Connect to the WHMCS database');
        $this->post(route('admin.settings.import.start'))->assertSessionHas('error', 'Save the WHMCS database details first.');

        app(Settings::class)->set('import.whmcs', ['host' => 'localhost', 'port' => 3306, 'database' => 'whmcs', 'username' => 'whmcs', 'password' => 'secret']);

        $this->post(route('admin.settings.import.start'))->assertSessionHas('status');
        Queue::assertPushed(ImportFromWhmcs::class);
        $this->assertSame('running', ImportFromWhmcs::status()['state']);
        $this->assertNotSame('{"host"', substr((string) DB::table('settings')->where('key', 'import.whmcs')->value('value'), 0, 7), 'The connection is stored encrypted');

        $this->get(route('admin.settings.import.index'))->assertSee('Stop')->assertDontSee('secret');
    }

    private function runImport(): void
    {
        $importer = new WhmcsImporter(self::CONNECTION);

        foreach (array_keys(WhmcsImporter::STEPS) as $step) {
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 1);
                $afterId = $result['last_id'];
            } while (! $result['done']);
        }
    }

    private function seedWhmcs(): void
    {
        $db = DB::connection(self::CONNECTION);

        $db->table('tblcurrencies')->insert([
            ['id' => 1, 'code' => 'USD', 'default' => 1],
            ['id' => 2, 'code' => 'IQD', 'default' => 0],
        ]);
        $db->table('tbladmins')->insert(['id' => 1, 'firstname' => 'Lana', 'lastname' => 'Staff', 'email' => 'lana@example.com']);
        $db->table('tblclients')->insert([
            ['id' => 1, 'firstname' => 'Raz', 'lastname' => 'Las', 'companyname' => 'Raz &amp; Co', 'email' => 'raz@example.com', 'country' => 'IQ', 'currency' => 2, 'credit' => '12.50', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-01-02'],
            ['id' => 2, 'firstname' => 'Sam', 'lastname' => 'Lee', 'companyname' => '', 'email' => 'sam@example.com', 'country' => 'US', 'currency' => 1, 'credit' => '0.00', 'status' => 'Inactive', 'password' => password_hash('sam-password-1', PASSWORD_BCRYPT, ['cost' => 4]), 'datecreated' => '2024-02-03'],
        ]);
        $db->table('tblusers')->insert(['id' => 5, 'email' => 'raz@example.com', 'password' => str_replace('$2y$', '$2a$', password_hash('raz-password-1', PASSWORD_BCRYPT, ['cost' => 4]))]);
        $db->table('tblusers_clients')->insert(['auth_user_id' => 5, 'client_id' => 1, 'owner' => 1]);

        $db->table('tblproductgroups')->insert(['id' => 1, 'name' => 'Hosting', 'headline' => 'Fast hosting', 'hidden' => 0, 'order' => 1]);
        $db->table('tblproducts')->insert(['id' => 3, 'gid' => 1, 'type' => 'hostingaccount', 'name' => 'Starter Hosting', 'description' => '', 'hidden' => 0, 'retired' => 0, 'showdomainoptions' => 1, 'paytype' => 'recurring', 'servertype' => 'cpanel', 'configoption1' => 'starter', 'autosetup' => 'payment', 'stockcontrol' => 0, 'qty' => 0, 'order' => 0]);
        $db->table('tblpricing')->insert([
            ['type' => 'product', 'relid' => 3, 'currency' => 1, 'msetupfee' => '0.00', 'qsetupfee' => '-1', 'ssetupfee' => '-1', 'asetupfee' => '0.00', 'bsetupfee' => '-1', 'tsetupfee' => '-1', 'monthly' => '5.00', 'quarterly' => '-1.00', 'semiannually' => '-1.00', 'annually' => '50.00', 'biennially' => '-1.00', 'triennially' => '-1.00'],
            ['type' => 'domainregister', 'relid' => 1, 'currency' => 1, 'msetupfee' => '12.99', 'qsetupfee' => '25.98', 'ssetupfee' => '-1', 'asetupfee' => '-1', 'bsetupfee' => '-1', 'tsetupfee' => '-1', 'monthly' => '-1', 'quarterly' => '-1', 'semiannually' => '-1', 'annually' => '-1', 'biennially' => '-1', 'triennially' => '-1'],
            ['type' => 'domainrenew', 'relid' => 1, 'currency' => 1, 'msetupfee' => '14.99', 'qsetupfee' => '-1', 'ssetupfee' => '-1', 'asetupfee' => '-1', 'bsetupfee' => '-1', 'tsetupfee' => '-1', 'monthly' => '-1', 'quarterly' => '-1', 'semiannually' => '-1', 'annually' => '-1', 'biennially' => '-1', 'triennially' => '-1'],
        ]);
        $db->table('tblservers')->insert([
            ['id' => 1, 'name' => 'Web 1', 'ipaddress' => '203.0.113.4', 'hostname' => 'web1.example.com', 'type' => 'cpanel', 'username' => 'root', 'secure' => 'on', 'port' => null, 'maxaccounts' => 200, 'nameserver1' => 'ns1.example.com', 'nameserver2' => 'ns2.example.com'],
            ['id' => 2, 'name' => 'Old', 'ipaddress' => '', 'hostname' => 'old.example.com', 'type' => 'somethingelse', 'username' => '', 'secure' => '', 'port' => null, 'maxaccounts' => 0, 'nameserver1' => '', 'nameserver2' => ''],
        ]);
        $db->table('tblhosting')->insert(['id' => 10, 'userid' => 1, 'packageid' => 3, 'server' => 1, 'regdate' => '2025-10-01', 'domain' => 'razstudio.com', 'firstpaymentamount' => '5.00', 'amount' => '5.00', 'billingcycle' => 'Monthly', 'nextduedate' => '2026-10-01', 'domainstatus' => 'Active', 'username' => 'razstud', 'suspendreason' => '']);

        $db->table('tbldomainpricing')->insert(['id' => 1, 'extension' => '.com', 'autoreg' => 'resellerclub', 'eppcode' => 1, 'order' => 1]);
        $db->table('tbldomains')->insert(['id' => 20, 'userid' => 1, 'type' => 'Register', 'registrationdate' => '2025-10-01', 'domain' => 'razstudio.com', 'firstpaymentamount' => '12.99', 'recurringamount' => '14.99', 'registrar' => 'resellerclub', 'registrationperiod' => 1, 'expirydate' => '2026-10-01', 'status' => 'Active', 'nextduedate' => '2026-10-01', 'donotrenew' => 0]);

        $db->table('tblinvoices')->insert([
            ['id' => 1000, 'userid' => 1, 'invoicenum' => '', 'date' => '2025-10-01', 'duedate' => '2025-10-01', 'datepaid' => '2025-10-01 12:00:00', 'subtotal' => '17.99', 'credit' => '0.00', 'tax' => '0.00', 'tax2' => '0.00', 'total' => '17.99', 'status' => 'Paid', 'paymentmethod' => 'fib', 'notes' => ''],
            ['id' => 1001, 'userid' => 1, 'invoicenum' => '', 'date' => '2026-09-20', 'duedate' => '2026-10-01', 'datepaid' => '0000-00-00 00:00:00', 'subtotal' => '5.00', 'credit' => '1.00', 'tax' => '0.00', 'tax2' => '0.00', 'total' => '4.00', 'status' => 'Unpaid', 'paymentmethod' => 'fib', 'notes' => ''],
        ]);
        $db->table('tblinvoiceitems')->insert([
            ['id' => 1, 'invoiceid' => 1000, 'userid' => 1, 'type' => 'Hosting', 'relid' => 10, 'description' => 'Starter Hosting', 'amount' => '5.00', 'duedate' => '2025-10-01'],
            ['id' => 2, 'invoiceid' => 1000, 'userid' => 1, 'type' => 'DomainRegister', 'relid' => 20, 'description' => 'Register razstudio.com', 'amount' => '12.99', 'duedate' => '2025-10-01'],
            ['id' => 3, 'invoiceid' => 1001, 'userid' => 1, 'type' => 'Hosting', 'relid' => 10, 'description' => 'Starter Hosting (01/10/2026 - 31/10/2026)', 'amount' => '5.00', 'duedate' => '2026-10-01'],
        ]);
        $db->table('tblaccounts')->insert([
            ['id' => 1, 'userid' => 1, 'currency' => 0, 'gateway' => 'fib', 'date' => '2025-10-01 12:00:00', 'description' => 'Invoice Payment', 'amountin' => '17.99', 'fees' => '0.00', 'amountout' => '0.00', 'transid' => 'txn-1', 'invoiceid' => 1000],
            ['id' => 2, 'userid' => 1, 'currency' => 0, 'gateway' => 'fib', 'date' => '2026-09-21 12:00:00', 'description' => 'Part payment', 'amountin' => '1.00', 'fees' => '0.00', 'amountout' => '0.00', 'transid' => 'txn-2', 'invoiceid' => 1001],
        ]);

        $db->table('tblticketdepartments')->insert(['id' => 1, 'name' => 'Technical', 'description' => '', 'email' => 'tech@example.com', 'hidden' => 0, 'order' => 1]);
        $db->table('tbltickets')->insert(['id' => 7, 'tid' => '482913', 'did' => 1, 'userid' => 1, 'date' => '2026-09-20 08:00:00', 'title' => 'Site down', 'message' => 'Where is my site?', 'status' => 'Answered', 'urgency' => 'High', 'lastreply' => '2026-09-20 09:00:00', 'service' => 'S10', 'ipaddress' => '198.51.100.7']);
        $db->table('tblticketreplies')->insert(['id' => 2, 'tid' => 7, 'userid' => 0, 'admin' => 'Lana Staff', 'date' => '2026-09-20 09:00:00', 'message' => 'Fixed &amp; working now.']);
    }

    private function createWhmcsTables(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $columns = [
            'tblconfiguration' => ['setting', 'value'],
            'tblcurrencies' => ['code', 'default'],
            'tbladmins' => ['firstname', 'lastname', 'email'],
            'tblclients' => ['firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'password', 'currency', 'credit', 'status', 'notes', 'datecreated'],
            'tblusers' => ['email', 'password'],
            'tblproductgroups' => ['name', 'headline', 'hidden', 'order'],
            'tblproducts' => ['gid', 'type', 'name', 'description', 'hidden', 'retired', 'showdomainoptions', 'paytype', 'servertype', 'configoption1', 'autosetup', 'stockcontrol', 'qty', 'order'],
            'tblpricing' => ['type', 'relid', 'currency', 'msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'],
            'tblservers' => ['name', 'ipaddress', 'hostname', 'type', 'username', 'secure', 'port', 'maxaccounts', 'nameserver1', 'nameserver2'],
            'tblhosting' => ['userid', 'packageid', 'server', 'regdate', 'domain', 'firstpaymentamount', 'amount', 'billingcycle', 'nextduedate', 'domainstatus', 'username', 'suspendreason'],
            'tbldomainpricing' => ['extension', 'autoreg', 'eppcode', 'order'],
            'tbldomains' => ['userid', 'type', 'registrationdate', 'domain', 'firstpaymentamount', 'recurringamount', 'registrar', 'registrationperiod', 'expirydate', 'status', 'nextduedate', 'donotrenew'],
            'tblinvoices' => ['userid', 'invoicenum', 'date', 'duedate', 'datepaid', 'subtotal', 'credit', 'tax', 'tax2', 'total', 'status', 'paymentmethod', 'notes'],
            'tblinvoiceitems' => ['invoiceid', 'userid', 'type', 'relid', 'description', 'amount', 'duedate'],
            'tblaccounts' => ['userid', 'currency', 'gateway', 'date', 'description', 'amountin', 'fees', 'amountout', 'transid', 'invoiceid'],
            'tblticketdepartments' => ['name', 'description', 'email', 'hidden', 'order'],
            'tbltickets' => ['tid', 'did', 'userid', 'date', 'title', 'message', 'status', 'urgency', 'lastreply', 'service', 'ipaddress'],
            'tblticketreplies' => ['tid', 'userid', 'admin', 'date', 'message'],
        ];

        foreach ($columns as $table => $names) {
            $schema->create($table, function (Blueprint $blueprint) use ($names): void {
                $blueprint->id();

                foreach ($names as $name) {
                    $blueprint->string($name)->nullable();
                }
            });
        }

        $schema->create('tblusers_clients', function (Blueprint $blueprint): void {
            $blueprint->integer('auth_user_id');
            $blueprint->integer('client_id');
            $blueprint->integer('owner');
        });
    }
}
