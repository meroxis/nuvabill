<?php

namespace Tests\Feature\Import;

use App\Billing\InvoicePaidHandler;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Billing\Wallet;
use App\Enums\BillingCycle;
use App\Enums\ClientStatus;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Import\Whmcs\WhmcsCrypt;
use App\Import\Whmcs\WhmcsImporter;
use App\Jobs\RunImport;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Domain;
use App\Models\ImportMapping;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
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

        $this->get(route('admin.settings.import.index'))->assertOk()->assertSee('Connect to the database');
        $this->post(route('admin.settings.import.start'))->assertSessionHas('error', 'Save the database details first.');

        app(Settings::class)->set('import.whmcs', ['host' => 'localhost', 'port' => 3306, 'database' => 'whmcs', 'username' => 'whmcs', 'password' => 'secret']);

        $this->post(route('admin.settings.import.start'))->assertSessionHas('status');
        Queue::assertPushed(RunImport::class);
        $this->assertSame('running', RunImport::status()['state']);
        $this->assertNotSame('{"host"', substr((string) DB::table('settings')->where('key', 'import.whmcs')->value('value'), 0, 7), 'The connection is stored encrypted');

        $this->get(route('admin.settings.import.index'))->assertSee('Stop')->assertDontSee('secret');
    }

    public function test_older_whmcs_passwords_work_once_and_are_then_replaced(): void
    {
        $this->seedWhmcs();
        DB::connection(self::CONNECTION)->table('tblclients')->where('id', 2)->update(['status' => 'Active', 'password' => md5('x7Q'.'sam-old-pass').':x7Q']);

        $this->runImport();

        $sam = Client::query()->where('email', 'sam@example.com')->firstOrFail();
        $this->assertNotNull($sam->legacy_password);

        $this->post(route('client.login'), ['email' => 'sam@example.com', 'password' => 'wrong-pass'])->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $this->post(route('client.login'), ['email' => 'sam@example.com', 'password' => 'sam-old-pass'])->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($sam, 'web');

        $sam->refresh();
        $this->assertNull($sam->legacy_password, 'The old hash is forgotten');
        $this->assertTrue(Hash::check('sam-old-pass', $sam->password));
    }

    public function test_the_whmcs_key_brings_server_and_service_passwords(): void
    {
        $this->seedWhmcs();
        $crypt = new WhmcsCrypt('whmcs-cc-hash');
        $whmcs = DB::connection(self::CONNECTION);
        $whmcs->table('tblservers')->where('id', 1)->update(['password' => $crypt->encrypt('root-secret'), 'accesshash' => "ABCD\nEF12"]);
        $whmcs->table('tblhosting')->where('id', 10)->update(['password' => $crypt->encrypt('razstud-pass')]);

        $this->assertNull((new WhmcsCrypt('another-key'))->decrypt($crypt->encrypt('root-secret')), 'A wrong key reads nothing');

        $this->runImport('whmcs-cc-hash');

        $server = Server::query()->firstOrFail();
        $this->assertSame('root-secret', $server->password);
        $this->assertSame('ABCDEF12', $server->api_token);
        $this->assertSame('razstud-pass', Service::query()->firstOrFail()->password);
    }

    public function test_wallet_credit_is_a_wallet_entry_and_a_new_run_adds_only_the_change(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertSame(1250, $raz->credit);
        $this->assertSame([1250], $raz->creditTransactions()->pluck('amount')->all());

        $this->runImport();
        $this->assertSame(1, $raz->creditTransactions()->count(), 'Nothing changed, nothing added');

        DB::connection(self::CONNECTION)->table('tblclients')->where('id', 1)->update(['credit' => '20.00']);
        $this->runImport();

        $this->assertSame(2000, $raz->fresh()->credit);
        $this->assertSame([1250, 750], $raz->creditTransactions()->orderBy('id')->pluck('amount')->all());
    }

    public function test_a_client_imported_before_keeps_the_balance_without_getting_it_twice(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        // How 0.4.8 left it: the balance copied, with no wallet entry.
        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        CreditTransaction::query()->delete();

        $this->runImport();

        $this->assertSame(1250, $raz->fresh()->credit);
        $this->assertSame(1, $raz->creditTransactions()->count());
    }

    public function test_a_client_imported_before_who_spent_part_of_the_balance_keeps_the_rest(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        // How 0.4.8 left it: 12.50 copied with no wallet entry. Raz then pays 10.00 of an invoice with it.
        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        CreditTransaction::query()->delete();
        app(Wallet::class)->change($raz, -1000, 'Paid invoice');

        $this->runImport();

        $this->assertSame(250, $raz->fresh()->credit, 'The spent money does not come back');
        $this->assertSame(1250, (int) $raz->creditTransactions()->where('description', 'Balance from WHMCS')->sum('amount'));

        DB::connection(self::CONNECTION)->table('tblclients')->where('id', 1)->update(['credit' => '15.00']);
        $this->runImport();

        $this->assertSame(500, $raz->fresh()->credit, 'Only the change in WHMCS is added');
    }

    public function test_imported_renewals_are_not_invoiced_again(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        $service = Service::query()->firstOrFail();
        $line = Invoice::query()->where('number', '1001')->firstOrFail()->items()->firstOrFail();
        $this->assertSame(RenewalGenerator::billingKey('service', $service->id, $service->next_due_date), $line->billing_key);

        app(RenewalGenerator::class)->generate($service->next_due_date);
        $this->assertSame(1, InvoiceItem::query()->where('service_id', $service->id)->whereDate('period_start', '2026-10-01')->count(), 'Nuvabill does not bill the period again');

        DB::connection(self::CONNECTION)->table('tblinvoices')->where('id', 1001)->update(['status' => 'Cancelled']);
        $this->runImport();

        $this->assertNull($line->fresh()->billing_key, 'Cancelled in WHMCS: the period is free to invoice again');
    }

    public function test_the_dry_run_changes_nothing_and_lists_the_problems(): void
    {
        $this->seedWhmcs();
        $whmcs = DB::connection(self::CONNECTION);
        $whmcs->table('tblclients')->insert(['id' => 3, 'firstname' => 'No', 'lastname' => 'Mail', 'email' => 'not-an-email', 'currency' => 1, 'credit' => '0', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-01-01']);
        $whmcs->table('tbltickets')->insert(['id' => 8, 'tid' => '100200', 'did' => 1, 'userid' => 0, 'date' => '2026-09-20 08:00:00', 'title' => 'Pre-sales', 'message' => 'Hello', 'status' => 'Open', 'urgency' => 'Low', 'lastreply' => '2026-09-20 08:00:00', 'service' => '', 'ipaddress' => '']);
        Client::factory()->create(['email' => 'sam@example.com']);

        $preview = (new WhmcsImporter(self::CONNECTION))->preflight()->toArray();

        $this->assertSame(1, Client::query()->count(), 'Nothing was imported');
        $this->assertSame(['label' => 'Clients', 'total' => 3, 'new' => 3, 'existing' => 0, 'skipped' => 0], $preview['steps']['clients']);

        $texts = collect($preview['problems'])->mapWithKeys(fn (array $problem): array => [$problem['text'] => $problem]);
        $this->assertSame(['not-an-email'], $texts['Clients without a valid email address: :count. They are skipped.']['examples']);
        $taken = $texts['Clients whose email is already used by a Nuvabill account: :count. They are skipped until you change the email of that account or delete it.'];
        $this->assertSame('warning', $taken['level'], 'Staff are warned, not told it is fine');
        $this->assertSame(['sam@example.com'], $taken['examples']);
        $this->assertSame(['Old (somethingelse)'], $texts['Servers with a module Nuvabill does not have: :count. They are skipped.']['examples']);
        $this->assertSame(1, $texts['Tickets from guests without a client account: :count. They are skipped.']['params']['count']);
        $this->assertSame(1, $texts['Clients with credit: :count. It becomes their Nuvabill wallet balance.']['params']['count']);
        $this->assertFalse((new WhmcsImporter(self::CONNECTION))->preflight()->hasErrors());
    }

    public function test_a_wrong_whmcs_key_stops_the_import_before_it_starts(): void
    {
        $this->seedWhmcs();
        DB::connection(self::CONNECTION)->table('tblservers')->where('id', 1)->update(['password' => (new WhmcsCrypt('right-key'))->encrypt('root-secret')]);

        $this->assertTrue((new WhmcsImporter(self::CONNECTION, 'wrong-key'))->preflight()->hasErrors());
        $this->assertFalse((new WhmcsImporter(self::CONNECTION, 'right-key'))->preflight()->hasErrors());

        Queue::fake();
        $this->signInAdmin();
        app(Settings::class)->set('import.connection', ['source' => 'whmcs', 'host' => 'localhost', 'port' => 3306, 'database' => 'whmcs', 'username' => 'whmcs', 'password' => 'secret', 'prefix' => '', 'key' => 'wrong-key']);
        app(Settings::class)->set('import.preview', ['source' => 'whmcs'] + (new WhmcsImporter(self::CONNECTION, 'wrong-key'))->preflight()->toArray());

        $this->get(route('admin.settings.import.index'))->assertOk()->assertSee('The WHMCS key did not open any of the 1 server passwords.', false)->assertDontSee('wrong-key');
        $this->post(route('admin.settings.import.start'))->assertSessionHas('error');
        Queue::assertNothingPushed();
    }

    public function test_an_account_signed_up_with_a_source_email_does_not_get_that_clients_records(): void
    {
        Mail::fake();
        $this->seedWhmcs();

        // Anyone can sign up with any email: Nuvabill does not prove they own it.
        $this->post(route('client.register'), [
            'first_name' => 'Raz',
            'last_name' => 'Las',
            'email' => 'raz@example.com',
            'country' => 'IQ',
            'password' => 'taken-pass-1',
            'password_confirmation' => 'taken-pass-1',
        ])->assertRedirect();
        $registered = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        auth('web')->logout();

        $texts = collect((new WhmcsImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');
        $taken = $texts['Clients whose email is already used by a Nuvabill account: :count. They are skipped until you change the email of that account or delete it.'];
        $this->assertSame('warning', $taken['level']);
        $this->assertSame(['raz@example.com'], $taken['examples']);

        $errors = $this->runImport();

        $this->assertFalse(ImportMapping::query()->where(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 1])->exists(), 'Not linked to the account');
        $this->assertContains(1, collect($errors)->where('step', 'clients')->pluck('id')->all());
        $this->assertSame(0, Service::query()->where('client_id', $registered->id)->count());
        $this->assertSame(0, Domain::query()->where('client_id', $registered->id)->count());
        $this->assertSame(0, Invoice::query()->where('client_id', $registered->id)->count());
        $this->assertSame(0, Ticket::query()->where('client_id', $registered->id)->count());

        $this->runImport();
        $this->assertSame(0, $registered->fresh()->credit, 'The source credit never reaches the account');
        $this->assertSame('Raz', $registered->fresh()->first_name);
    }

    public function test_a_client_linked_by_an_earlier_version_is_never_changed(): void
    {
        $this->seedWhmcs();
        $mer = Client::factory()->create(['email' => 'sam@example.com', 'first_name' => 'Mer', 'last_name' => 'Las', 'status' => ClientStatus::Active, 'notes' => 'VIP', 'currency' => 'USD']);
        Activity::log('client.created', 'Client created', $mer);
        app(Wallet::class)->change($mer, 5000, 'Top-up');

        // How an import before 0.6.12 left it: a plain client mapping to the existing account.
        ImportMapping::query()->create(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 2, 'local_id' => $mer->id]);

        $texts = collect((new WhmcsImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');
        $this->assertSame(['sam@example.com'], $texts['Clients an earlier import linked by email to a Nuvabill account: :count. They are not changed; check that each account belongs to the same person.']['examples']);

        $this->runImport();
        $this->runImport();

        $mer->refresh();
        $this->assertSame('Mer', $mer->first_name);
        $this->assertSame('Las', $mer->last_name);
        $this->assertSame('VIP', $mer->notes);
        $this->assertSame(ClientStatus::Active, $mer->status, 'Not closed or made inactive by the old system');
        $this->assertSame(5000, $mer->credit);
        $this->assertFalse(CreditTransaction::query()->where('client_id', $mer->id)->where('description', 'Balance from WHMCS')->exists());
        $this->assertTrue(ImportMapping::query()->where(['source' => 'whmcs', 'entity' => 'client_link', 'source_id' => 2])->exists());
    }

    public function test_a_department_with_the_same_name_is_linked_and_never_changed(): void
    {
        $this->seedWhmcs();
        $department = TicketDepartment::query()->create(['name' => 'Technical', 'email' => 'help@nuvabill.test', 'description' => 'Ours', 'is_visible' => true]);

        $this->runImport();
        $this->runImport();

        $this->assertSame(1, TicketDepartment::query()->where('name', 'Technical')->count());
        $this->assertSame('help@nuvabill.test', $department->fresh()->email);
        $this->assertSame('Ours', $department->fresh()->description);
        $this->assertSame($department->id, Ticket::query()->firstOrFail()->ticket_department_id);
    }

    public function test_a_linked_account_in_another_currency_gets_no_unconverted_records(): void
    {
        $this->seedWhmcs();
        // Raz uses IQD in WHMCS; the account an earlier version linked him to uses USD.
        $account = Client::factory()->create(['email' => 'raz@example.com', 'currency' => 'USD']);
        Activity::log('client.registered', 'Signed up', $account, $account, $account);
        ImportMapping::query()->create(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 1, 'local_id' => $account->id]);

        $errors = $this->runImport();

        $this->assertSame(0, Service::query()->where('client_id', $account->id)->count());
        $this->assertSame(0, Domain::query()->where('client_id', $account->id)->count());
        $this->assertSame(0, Invoice::query()->where('client_id', $account->id)->count());
        $this->assertFalse(Service::query()->where('currency', 'USD')->where('recurring_amount', 500)->exists(), 'No IQD amount is relabelled as USD');
        $this->assertStringContainsString('IQD', (string) collect($errors)->where('step', 'clients')->firstWhere('id', 1)['error']);

        $this->runImport();
        $this->assertSame(0, Service::query()->count(), 'Later runs keep it out too');
    }

    public function test_an_account_that_signed_up_itself_and_was_linked_before_gets_no_new_records(): void
    {
        Mail::fake();
        $this->seedWhmcs();

        // An earlier version linked WHMCS client Raz by email to an account someone signed up with.
        $this->post(route('client.register'), [
            'first_name' => 'Raz',
            'last_name' => 'Las',
            'email' => 'raz@example.com',
            'country' => 'IQ',
            'password' => 'taken-pass-1',
            'password_confirmation' => 'taken-pass-1',
        ])->assertRedirect();
        auth('web')->logout();
        $account = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $account->forceFill(['currency' => 'IQD'])->save();
        ImportMapping::query()->create(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 1, 'local_id' => $account->id]);

        $texts = collect((new WhmcsImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');
        $this->assertSame(['raz@example.com'], $texts['Clients an earlier import linked by email to an account that signed up by itself, without proving the email is theirs: :count. Those accounts get no new services, domains, invoices or tickets; check them and add these by hand.']['examples']);

        $errors = $this->runImport();

        $this->assertStringContainsString('signed up by itself', (string) collect($errors)->where('step', 'clients')->firstWhere('id', 1)['error']);
        $this->assertSame(0, Service::query()->where('client_id', $account->id)->count());
        $this->assertSame(0, Domain::query()->where('client_id', $account->id)->count());
        $this->assertSame(0, Invoice::query()->where('client_id', $account->id)->count());
        $this->assertSame(0, Ticket::query()->where('client_id', $account->id)->count());
        $this->assertFalse(ImportMapping::query()->where(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 1])->exists());
        $this->assertTrue(ImportMapping::query()->where(['source' => 'whmcs', 'entity' => 'client_link', 'source_id' => 1])->exists());

        // A service added in WHMCS between runs does not reach the account either, and staff are told again.
        DB::connection(self::CONNECTION)->table('tblhosting')->insert(['id' => 11, 'userid' => 1, 'packageid' => 3, 'server' => 1, 'regdate' => '2026-10-02', 'domain' => 'raz.example', 'firstpaymentamount' => '5.00', 'amount' => '5.00', 'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-02', 'domainstatus' => 'Active', 'username' => 'razex', 'suspendreason' => '']);
        $errors = $this->runImport();

        $this->assertSame(0, Service::query()->where('client_id', $account->id)->count());
        $this->assertNotNull(collect($errors)->where('step', 'clients')->firstWhere('id', 1));
        $this->assertSame(0, $account->fresh()->credit);
    }

    public function test_an_account_that_proved_its_email_and_was_linked_before_still_gets_records(): void
    {
        $this->seedWhmcs();
        // Signed up with a sign-in provider, which confirmed the email, and never changed it.
        $account = Client::factory()->create(['email' => 'raz@example.com', 'currency' => 'IQD', 'email_verified_at' => now()]);
        Activity::log('client.registered', 'Signed up', $account, $account, $account);
        ImportMapping::query()->create(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 1, 'local_id' => $account->id]);

        $this->assertSame([], $this->runImport());

        $this->assertSame(1, Service::query()->where('client_id', $account->id)->count());
        $this->assertSame(1, Domain::query()->where('client_id', $account->id)->count());

        // Changing the details on the account page could have changed the email, so it no longer counts.
        Activity::log('client.profile', 'Client updated their details', $account);
        DB::connection(self::CONNECTION)->table('tblhosting')->insert(['id' => 11, 'userid' => 1, 'packageid' => 3, 'server' => 1, 'regdate' => '2026-10-02', 'domain' => 'raz.example', 'firstpaymentamount' => '5.00', 'amount' => '5.00', 'billingcycle' => 'Monthly', 'nextduedate' => '2026-11-02', 'domainstatus' => 'Active', 'username' => 'razex', 'suspendreason' => '']);

        $this->assertNotSame([], $this->runImport());
        $this->assertSame(1, Service::query()->where('client_id', $account->id)->count());
    }

    public function test_a_service_status_set_in_nuvabill_is_kept_on_a_rerun(): void
    {
        $this->seedWhmcs();
        $this->runImport();
        $whmcs = DB::connection(self::CONNECTION);
        $service = Service::query()->firstOrFail();

        // Nuvabill suspends it for an unpaid invoice; WHMCS still shows it active.
        $service->forceFill(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDay(), 'suspension_reason' => InvoicePaidHandler::OVERDUE_REASON])->save();
        Activity::log('service.suspended', 'Suspended', $service);
        $this->runImport();

        $service->refresh();
        $this->assertSame(ServiceStatus::Suspended, $service->status, 'The panel account is suspended, so the record says so');
        $this->assertSame(InvoicePaidHandler::OVERDUE_REASON, $service->suspension_reason, 'Paying the invoice still unsuspends it');
        $this->assertNotNull($service->suspended_at);

        // The client pays and Nuvabill unsuspends it; WHMCS suspends it later, never having seen the payment.
        $service->forceFill(['status' => ServiceStatus::Active, 'suspended_at' => null, 'suspension_reason' => null])->save();
        Activity::log('service.unsuspended', 'Unsuspended', $service);
        $whmcs->table('tblhosting')->where('id', 10)->update(['domainstatus' => 'Suspended', 'suspendreason' => 'Overdue']);
        $this->runImport();

        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertNull($service->suspended_at);
        $this->assertNull($service->suspension_reason);

        // Still so after the activity log is cleaned up.
        ActivityLog::query()->delete();
        $this->runImport();
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);

        // WHMCS can still end it.
        $whmcs->table('tblhosting')->where('id', 10)->update(['domainstatus' => 'Terminated']);
        $this->runImport();
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        $this->assertNotNull($service->fresh()->terminated_at);
    }

    public function test_a_domain_renewed_in_nuvabill_stays_active_on_a_rerun(): void
    {
        $this->seedWhmcs();
        DB::connection(self::CONNECTION)->table('tbldomains')->where('id', 20)->update(['status' => 'Expired']);
        $this->runImport();

        $domain = Domain::query()->firstOrFail();
        $this->assertSame(DomainStatus::Expired, $domain->status);
        $domain->forceFill(['status' => DomainStatus::Active, 'expires_at' => '2027-10-01'])->save();
        Activity::log('domain.renewed', 'Renewed', $domain);

        $this->runImport();

        $domain->refresh();
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertSame('2027-10-01', $domain->expires_at->toDateString());
    }

    public function test_a_rerun_keeps_wallet_money_added_in_nuvabill(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        // No balance in WHMCS, so the first run wrote no wallet entry.
        $client = Client::query()->where('email', 'sam@example.com')->firstOrFail();
        app(Wallet::class)->change($client, 5000, 'Added funds');

        $this->runImport();

        $this->assertSame(5000, $client->fresh()->credit);
        $this->assertSame(0, (int) $client->creditTransactions()->where('description', 'Balance from WHMCS')->sum('amount'));

        DB::connection(self::CONNECTION)->table('tblclients')->where('id', 2)->update(['credit' => '10.00']);
        $this->runImport();

        $this->assertSame(6000, $client->fresh()->credit, 'Only the change in WHMCS is added');
    }

    public function test_running_it_again_keeps_payments_made_in_nuvabill(): void
    {
        Mail::fake();
        $this->seedWhmcs();
        $this->runImport();

        $invoice = Invoice::query()->where('number', '1001')->firstOrFail();
        app(PaymentRecorder::class)->record($invoice, $invoice->balance(), 'banktransfer', 'nb-1');
        $service = Service::query()->firstOrFail();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('2026-11-01', $service->fresh()->next_due_date->toDateString());

        // WHMCS never saw that payment.
        $this->runImport();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertSame(500, $invoice->amount_paid);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('2026-11-01', $service->fresh()->next_due_date->toDateString(), 'The renewal is not undone');

        // Cancelled in WHMCS afterwards: the paid period is not freed to be invoiced again.
        DB::connection(self::CONNECTION)->table('tblinvoices')->where('id', 1001)->update(['status' => 'Cancelled']);
        $this->runImport();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotNull($invoice->items()->firstOrFail()->billing_key);

        // Moving forward in WHMCS still comes across.
        DB::connection(self::CONNECTION)->table('tblhosting')->where('id', 10)->update(['nextduedate' => '2026-12-01']);
        $this->runImport();
        $this->assertSame('2026-12-01', $service->fresh()->next_due_date->toDateString());
    }

    public function test_an_invoice_cancelled_in_nuvabill_stays_cancelled(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        $invoice = Invoice::query()->where('number', '1001')->firstOrFail();
        $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();

        $this->runImport();

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_a_draft_published_in_whmcs_gets_its_number_on_the_next_run(): void
    {
        $this->seedWhmcs();
        $whmcs = DB::connection(self::CONNECTION);
        $whmcs->table('tblinvoices')->insert(['id' => 1002, 'userid' => 1, 'invoicenum' => '', 'date' => '2026-10-20', 'duedate' => '2026-11-01', 'datepaid' => '0000-00-00 00:00:00', 'subtotal' => '3.00', 'credit' => '0.00', 'tax' => '0.00', 'tax2' => '0.00', 'total' => '3.00', 'status' => 'Draft', 'paymentmethod' => 'fib', 'notes' => '']);
        $whmcs->table('tblinvoiceitems')->insert(['id' => 4, 'invoiceid' => 1002, 'userid' => 1, 'type' => 'Hosting', 'relid' => 10, 'description' => 'Starter Hosting', 'amount' => '3.00', 'duedate' => '2026-11-01']);

        $this->runImport();

        $invoice = Invoice::query()->findOrFail(ImportMapping::query()->where(['source' => 'whmcs', 'entity' => 'invoice', 'source_id' => 1002])->value('local_id'));
        $this->assertSame(InvoiceStatus::Draft, $invoice->status);
        $this->assertNull($invoice->number);

        $whmcs->table('tblinvoices')->where('id', 1002)->update(['status' => 'Unpaid', 'subtotal' => '5.00', 'total' => '5.00']);
        $whmcs->table('tblinvoiceitems')->where('id', 4)->update(['amount' => '5.00']);
        $count = Invoice::query()->count();

        $this->runImport();

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame('1002', $invoice->number);
        $this->assertStringStartsNotWith('Draft', $invoice->displayNumber());
        $this->assertSame($count, Invoice::query()->count(), 'No copy was made');
        $this->assertSame([500], $invoice->items()->pluck('amount')->all(), 'The lines are the published ones');
        $this->assertSame('2026-11-01', $invoice->items()->firstOrFail()->period_start->toDateString());
    }

    public function test_a_second_whmcs_database_is_refused_instead_of_merged_by_id(): void
    {
        $this->seedWhmcs();
        $this->runImport();
        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();

        config(['database.connections.whmcs_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('whmcs_other');
        $this->createWhmcsTables('whmcs_other');
        $other = DB::connection('whmcs_other');
        $other->table('tblcurrencies')->insert(['id' => 1, 'code' => 'USD', 'default' => 1]);
        $other->table('tblclients')->insert(['id' => 1, 'firstname' => 'Mer', 'lastname' => 'Las', 'email' => 'mer@example.com', 'currency' => 1, 'credit' => '0.00', 'status' => 'Closed', 'password' => '', 'datecreated' => '2023-05-06']);
        $other->table('tblhosting')->insert(['id' => 10, 'userid' => 1, 'packageid' => 3, 'server' => 1, 'regdate' => '2025-01-01', 'domain' => 'merlas.test', 'amount' => '9.00', 'billingcycle' => 'Monthly', 'nextduedate' => '2026-01-01', 'domainstatus' => 'Active', 'username' => 'merlas']);

        $preview = (new WhmcsImporter('whmcs_other'))->preflight();
        $this->assertTrue($preview->hasErrors());
        $this->assertContains('Nuvabill already holds records imported from another :system database. Importing a second one is not supported.', collect($preview->toArray()['problems'])->pluck('text')->all());

        try {
            (new WhmcsImporter('whmcs_other'))->run('clients');
            $this->fail('A second database must not be imported');
        } catch (RuntimeException) {
        }

        $raz->refresh();
        $this->assertSame('Raz', $raz->first_name);
        $this->assertSame(ClientStatus::Active, $raz->status);
        $this->assertFalse(Client::query()->where('email', 'mer@example.com')->exists());
        $this->assertFalse(Service::query()->where('domain', 'merlas.test')->exists());
        $this->assertSame('razstudio.com', Service::query()->firstOrFail()->domain);
    }

    public function test_running_again_on_a_copy_of_the_same_database_still_works(): void
    {
        $this->seedWhmcs();
        $this->runImport();

        config(['database.connections.whmcs_copy' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('whmcs_copy');
        $this->createWhmcsTables('whmcs_copy');
        $this->seedWhmcs('whmcs_copy');

        $this->assertFalse((new WhmcsImporter('whmcs_copy'))->preflight()->hasErrors());
        $this->assertSame([], $this->runImport(connection: 'whmcs_copy'));
        $this->assertSame(2, Client::query()->count());
        $this->assertSame(1, Service::query()->count());
    }

    public function test_a_piece_of_a_stopped_import_does_not_join_the_next_run(): void
    {
        Queue::fake();
        $this->signInAdmin();
        app(Settings::class)->set('import.connection', ['source' => 'whmcs', 'host' => 'localhost', 'port' => 3306, 'database' => 'whmcs', 'username' => 'whmcs', 'password' => 'secret', 'prefix' => '']);

        $this->post(route('admin.settings.import.start'))->assertSessionHas('status');
        $old = Queue::pushed(RunImport::class)->first();
        $this->assertNotNull($old->runId);
        $stale = new RunImport(2, 5, $old->runId);

        $this->post(route('admin.settings.import.cancel'));
        $this->post(route('admin.settings.import.start'))->assertSessionHas('status');
        $new = RunImport::status();
        $this->assertNotSame($old->runId, $new['run_id']);

        $stale->handle(app(Settings::class));

        app(Settings::class)->flush();
        $this->assertSame($new, RunImport::status(), 'The new run is left as it was');
        Queue::assertPushed(RunImport::class, 2);
        $this->assertSame(0, Client::query()->count());
    }

    public function test_the_dry_run_keeps_queries_small_for_large_installs(): void
    {
        $this->seedWhmcs();
        $whmcs = DB::connection(self::CONNECTION);
        $hash = password_hash('raz-password-1', PASSWORD_BCRYPT, ['cost' => 4]);

        foreach (array_chunk(range(100, 1599), 250) as $ids) {
            $whmcs->table('tblclients')->insert(array_map(fn (int $id): array => ['id' => $id, 'firstname' => 'Raz', 'lastname' => 'Las', 'email' => "raz{$id}@example.com", 'currency' => 1, 'credit' => '0.00', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-01-01'], $ids));
            $whmcs->table('tblusers')->insert(array_map(fn (int $id): array => ['id' => $id, 'email' => "raz{$id}@example.com", 'password' => $hash], $ids));
            $whmcs->table('tblusers_clients')->insert(array_map(fn (int $id): array => ['auth_user_id' => $id, 'client_id' => $id, 'owner' => 1], $ids));
            ImportMapping::query()->insert(array_map(fn (int $id): array => ['source' => 'whmcs', 'entity' => 'client', 'source_id' => $id + 5000, 'local_id' => $id], $ids));
        }

        $most = 0;
        DB::listen(function (QueryExecuted $query) use (&$most): void {
            $most = max($most, count($query->bindings));
        });

        $preview = (new WhmcsImporter(self::CONNECTION))->preflight()->toArray();

        $this->assertLessThanOrEqual(1000, $most, 'No query binds every client');
        $this->assertSame(1502, $preview['steps']['clients']['total']);
        $texts = collect($preview['problems'])->keyBy('text');
        $this->assertArrayNotHasKey('Clients with a password Nuvabill cannot read: :count. They choose a new one on the sign-in page.', $texts->all(), 'The owner passwords are still found');
    }

    public function test_new_accounts_share_one_placeholder_password_hash(): void
    {
        $this->seedWhmcs();
        $whmcs = DB::connection(self::CONNECTION);
        $whmcs->table('tblclients')->insert([
            ['id' => 3, 'firstname' => 'Mer', 'lastname' => 'Las', 'email' => 'mer@example.com', 'currency' => 1, 'credit' => '0.00', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-03-01'],
            ['id' => 4, 'firstname' => 'Mer', 'lastname' => 'Las', 'email' => 'mer.las@example.com', 'currency' => 1, 'credit' => '0.00', 'status' => 'Active', 'password' => '', 'datecreated' => '2024-03-02'],
        ]);
        $whmcs->table('tbladmins')->insert(['id' => 2, 'firstname' => 'Raz', 'lastname' => 'Las', 'email' => 'raz.staff@example.com']);

        $this->runImport();

        $passwords = Client::query()->whereIn('email', ['mer@example.com', 'mer.las@example.com'])->pluck('password');
        $this->assertCount(2, $passwords);
        $this->assertCount(1, $passwords->unique(), 'One hash for the run, not one per row');
        $this->assertFalse(Hash::check('', $passwords->first()));
        $this->assertCount(1, Admin::query()->pluck('password')->unique());

        $this->post(route('client.login'), ['email' => 'mer@example.com', 'password' => 'not-the-password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
    }

    /**
     * @return list<array{step: string, id: int, error: string}> The rows that were skipped with a reason.
     */
    private function runImport(?string $key = null, string $connection = self::CONNECTION): array
    {
        $importer = new WhmcsImporter($connection, $key);
        $errors = [];

        foreach (array_keys(WhmcsImporter::STEPS) as $step) {
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 1);
                $afterId = $result['last_id'];

                foreach ($result['errors'] as $error) {
                    $errors[] = ['step' => $step] + $error;
                }
            } while (! $result['done']);
        }

        return $errors;
    }

    private function seedWhmcs(string $connection = self::CONNECTION): void
    {
        $db = DB::connection($connection);

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

    private function createWhmcsTables(string $connection = self::CONNECTION): void
    {
        $schema = Schema::connection($connection);
        $columns = [
            'tblconfiguration' => ['setting', 'value'],
            'tblcurrencies' => ['code', 'default'],
            'tbladmins' => ['firstname', 'lastname', 'email'],
            'tblclients' => ['firstname', 'lastname', 'companyname', 'email', 'address1', 'address2', 'city', 'state', 'postcode', 'country', 'phonenumber', 'password', 'currency', 'credit', 'status', 'notes', 'datecreated'],
            'tblusers' => ['email', 'password'],
            'tblproductgroups' => ['name', 'headline', 'hidden', 'order'],
            'tblproducts' => ['gid', 'type', 'name', 'description', 'hidden', 'retired', 'showdomainoptions', 'paytype', 'servertype', 'configoption1', 'autosetup', 'stockcontrol', 'qty', 'order'],
            'tblpricing' => ['type', 'relid', 'currency', 'msetupfee', 'qsetupfee', 'ssetupfee', 'asetupfee', 'bsetupfee', 'tsetupfee', 'monthly', 'quarterly', 'semiannually', 'annually', 'biennially', 'triennially'],
            'tblservers' => ['name', 'ipaddress', 'hostname', 'type', 'username', 'password', 'accesshash', 'secure', 'port', 'maxaccounts', 'nameserver1', 'nameserver2'],
            'tblhosting' => ['userid', 'packageid', 'server', 'regdate', 'domain', 'firstpaymentamount', 'amount', 'billingcycle', 'nextduedate', 'domainstatus', 'username', 'password', 'suspendreason'],
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
