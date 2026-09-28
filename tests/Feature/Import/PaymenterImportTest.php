<?php

namespace Tests\Feature\Import;

use App\Billing\RenewalGenerator;
use App\Enums\BillingCycle;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Import\Paymenter\PaymenterImporter;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\Transaction;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PaymenterImportTest extends TestCase
{
    use RefreshDatabase;

    private const CONNECTION = 'paymenter_test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.'.self::CONNECTION => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge(self::CONNECTION);
        $this->createTables();
        $this->seedPaymenter();
    }

    public function test_it_imports_a_paymenter_database(): void
    {
        $this->runImport();

        $raz = Client::query()->where('email', 'raz@example.com')->firstOrFail();
        $this->assertSame('Raz Studio', $raz->company_name);
        $this->assertSame('Erbil', $raz->city);
        $this->assertSame('IQ', $raz->country, 'Country names become codes');
        $this->assertSame('EUR', $raz->currency, 'The currency of their invoices');
        $this->assertSame(800, $raz->credit, 'Only credit in their currency');
        $this->assertTrue(Hash::check('raz-password-1', $raz->password));

        $this->assertSame(2, Client::query()->count(), 'Staff can also be clients');
        $this->assertFalse(Admin::query()->where('email', 'lana@example.com')->value('is_active'));

        $product = Product::query()->where('name', 'Game Server')->firstOrFail();
        $this->assertSame(1000, $product->prices()->where('currency', 'EUR')->where('billing_cycle', 'monthly')->value('price'));
        $this->assertSame(10000, $product->prices()->where('currency', 'EUR')->where('billing_cycle', 'annually')->value('price'));
        $this->assertFalse($product->prices()->where('billing_cycle', 'weekly')->exists());
        $this->assertSame(2, $product->prices()->count(), 'The hourly plan is left out');

        $service = Service::query()->firstOrFail();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame(BillingCycle::Monthly, $service->billing_cycle);
        $this->assertSame('play.razstudio.com', $service->domain);
        $this->assertSame('2026-10-01', $service->next_due_date->toDateString());

        $unpaid = Invoice::query()->where('number', 'INV-2')->firstOrFail();
        $this->assertSame(InvoiceStatus::Unpaid, $unpaid->status);
        $this->assertSame(1000, $unpaid->total);
        $this->assertSame(RenewalGenerator::billingKey('service', $service->id, $service->next_due_date), $unpaid->items()->firstOrFail()->billing_key);
        $this->assertSame(InvoiceStatus::Paid, Invoice::query()->where('number', 'INV-1')->firstOrFail()->status);

        $this->assertSame(1, Transaction::query()->where('reference', 'pi_123')->where('gateway', 'stripe')->count());
        $this->assertSame(0, Transaction::query()->where('reference', 'credit-use')->count(), 'Payments from credit are left out');

        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame(TicketStatus::Answered, $ticket->status);
        $this->assertSame('Billing', TicketDepartment::query()->find($ticket->ticket_department_id)->name);
        $this->assertSame(['client', 'admin'], $ticket->replies->pluck('author_type')->all());
    }

    public function test_running_it_again_makes_no_copies(): void
    {
        $this->runImport();
        DB::connection(self::CONNECTION)->table('services')->where('id', 20)->update(['status' => 'suspended']);

        $this->runImport();

        $this->assertSame(2, Client::query()->count());
        $this->assertSame(1, Service::query()->count());
        $this->assertSame(ServiceStatus::Suspended, Service::query()->first()->status);
        $this->assertSame(2, Invoice::query()->count());
        $this->assertSame(2, Ticket::query()->first()->replies()->count());
        $this->assertSame(800, Client::query()->where('email', 'raz@example.com')->value('credit'));
    }

    public function test_the_dry_run_names_what_stays_behind(): void
    {
        $texts = collect((new PaymenterImporter(self::CONNECTION))->preflight()->toArray()['problems'])->keyBy('text');

        $this->assertSame(['Pterodactyl'], $texts['Server connections, not imported: :count. Add your servers in Nuvabill, then choose them on the products.']['examples']);
        $this->assertSame(['1 hour'], $texts['Prices with a billing period Nuvabill does not have: :count. They are not imported.']['examples']);
        $this->assertSame(0, Client::query()->count());
    }

    private function runImport(): void
    {
        $importer = new PaymenterImporter(self::CONNECTION);

        foreach (array_keys(PaymenterImporter::STEPS) as $step) {
            $afterId = 0;

            do {
                $result = $importer->run($step, $afterId, 1);
                $this->assertSame([], $result['errors'], "Step {$step}");
                $afterId = $result['last_id'];
            } while (! $result['done']);
        }
    }

    private function seedPaymenter(): void
    {
        $db = DB::connection(self::CONNECTION);

        $db->table('currencies')->insert([['id' => 1, 'code' => 'EUR'], ['id' => 2, 'code' => 'USD']]);
        $db->table('users')->insert([
            ['id' => 1, 'first_name' => 'Lana', 'last_name' => 'Staff', 'email' => 'lana@example.com', 'role_id' => 1, 'password' => password_hash('staff-pass', PASSWORD_BCRYPT, ['cost' => 4]), 'created_at' => '2024-01-01 10:00:00'],
            ['id' => 2, 'first_name' => 'Raz', 'last_name' => 'Las', 'email' => 'raz@example.com', 'role_id' => null, 'password' => password_hash('raz-password-1', PASSWORD_BCRYPT, ['cost' => 4]), 'created_at' => '2024-01-02 10:00:00'],
        ]);
        $db->table('properties')->insert([
            ['id' => 1, 'model_type' => 'App\Models\User', 'model_id' => 2, 'key' => 'company_name', 'value' => 'Raz Studio'],
            ['id' => 2, 'model_type' => 'App\Models\User', 'model_id' => 2, 'key' => 'city', 'value' => 'Erbil'],
            ['id' => 3, 'model_type' => 'App\Models\User', 'model_id' => 2, 'key' => 'country', 'value' => 'Iraq'],
            ['id' => 4, 'model_type' => 'App\Models\Service', 'model_id' => 20, 'key' => 'domain', 'value' => 'play.razstudio.com'],
        ]);
        $db->table('credits')->insert([
            ['id' => 1, 'user_id' => 2, 'currency_code' => 'EUR', 'amount' => '8.00'],
            ['id' => 2, 'user_id' => 2, 'currency_code' => 'USD', 'amount' => '3.00'],
        ]);

        $db->table('extensions')->insert([
            ['id' => 1, 'name' => 'Stripe', 'extension' => 'Stripe', 'type' => 'gateway'],
            ['id' => 2, 'name' => 'Pterodactyl', 'extension' => 'Pterodactyl', 'type' => 'server'],
        ]);
        $db->table('categories')->insert(['id' => 1, 'slug' => 'games', 'name' => 'Game servers', 'description' => '<p>Fast</p>', 'sort' => 1]);
        $db->table('products')->insert(['id' => 3, 'category_id' => 1, 'name' => 'Game Server', 'slug' => 'game-server', 'description' => 'Minecraft', 'stock' => null, 'sort' => 1]);
        $db->table('plans')->insert([
            ['id' => 1, 'name' => 'Monthly', 'priceable_type' => 'App\Models\Product', 'priceable_id' => 3, 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'month'],
            ['id' => 2, 'name' => 'Yearly', 'priceable_type' => 'App\Models\Product', 'priceable_id' => 3, 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'year'],
            ['id' => 3, 'name' => 'Hourly', 'priceable_type' => 'App\Models\Product', 'priceable_id' => 3, 'type' => 'recurring', 'billing_period' => 1, 'billing_unit' => 'hour'],
        ]);
        $db->table('prices')->insert([
            ['id' => 1, 'plan_id' => 1, 'price' => '10.00', 'setup_fee' => '0', 'currency_code' => 'EUR'],
            ['id' => 2, 'plan_id' => 2, 'price' => '100.00', 'setup_fee' => '0', 'currency_code' => 'EUR'],
            ['id' => 3, 'plan_id' => 3, 'price' => '0.02', 'setup_fee' => '0', 'currency_code' => 'EUR'],
        ]);

        $db->table('services')->insert(['id' => 20, 'status' => 'active', 'product_id' => 3, 'user_id' => 2, 'currency_code' => 'EUR', 'quantity' => 1, 'price' => '10.00', 'plan_id' => 1, 'expires_at' => '2026-10-01 00:00:00', 'created_at' => '2025-10-01 10:00:00']);
        $db->table('invoices')->insert([
            ['id' => 1, 'number' => 'INV-1', 'status' => 'paid', 'due_at' => '2025-10-01', 'currency_code' => 'EUR', 'user_id' => 2, 'created_at' => '2025-10-01 10:00:00', 'updated_at' => '2025-10-01 11:00:00'],
            ['id' => 2, 'number' => 'INV-2', 'status' => 'pending', 'due_at' => '2026-10-01', 'currency_code' => 'EUR', 'user_id' => 2, 'created_at' => '2026-09-24 10:00:00', 'updated_at' => '2026-09-24 10:00:00'],
        ]);
        $db->table('invoice_items')->insert([
            ['id' => 1, 'invoice_id' => 1, 'price' => '10.00', 'quantity' => 1, 'description' => 'Game Server', 'reference_type' => 'App\Models\Service', 'reference_id' => 20],
            ['id' => 2, 'invoice_id' => 2, 'price' => '10.00', 'quantity' => 1, 'description' => 'Game Server renewal', 'reference_type' => 'App\Models\Service', 'reference_id' => 20],
        ]);
        $db->table('invoice_transactions')->insert([
            ['id' => 1, 'invoice_id' => 1, 'gateway_id' => 1, 'amount' => '10.00', 'fee' => '0.30', 'transaction_id' => 'pi_123', 'status' => 'succeeded', 'is_credit_transaction' => 0, 'created_at' => '2025-10-01 11:00:00'],
            ['id' => 2, 'invoice_id' => 2, 'gateway_id' => null, 'amount' => '2.00', 'fee' => null, 'transaction_id' => 'credit-use', 'status' => 'succeeded', 'is_credit_transaction' => 1, 'created_at' => '2026-09-25 11:00:00'],
        ]);

        $db->table('tickets')->insert(['id' => 7, 'subject' => 'Refund question', 'status' => 'replied', 'priority' => 'high', 'department' => 'Billing', 'user_id' => 2, 'service_id' => 20, 'created_at' => '2026-09-20 08:00:00', 'updated_at' => '2026-09-20 09:00:00']);
        $db->table('ticket_messages')->insert([
            ['id' => 1, 'ticket_id' => 7, 'user_id' => 2, 'message' => 'Can I get a refund?', 'created_at' => '2026-09-20 08:00:00'],
            ['id' => 2, 'ticket_id' => 7, 'user_id' => 1, 'message' => 'Yes, done.', 'created_at' => '2026-09-20 09:00:00'],
        ]);
    }

    private function createTables(): void
    {
        $columns = [
            'currencies' => ['code'],
            'users' => ['first_name', 'last_name', 'email', 'role_id', 'password', 'created_at'],
            'properties' => ['model_type', 'model_id', 'key', 'value'],
            'credits' => ['user_id', 'currency_code', 'amount'],
            'extensions' => ['name', 'extension', 'type'],
            'categories' => ['slug', 'name', 'description', 'sort'],
            'products' => ['category_id', 'name', 'slug', 'description', 'stock', 'sort'],
            'plans' => ['name', 'priceable_type', 'priceable_id', 'type', 'billing_period', 'billing_unit'],
            'prices' => ['plan_id', 'price', 'setup_fee', 'currency_code'],
            'services' => ['status', 'product_id', 'user_id', 'currency_code', 'quantity', 'price', 'plan_id', 'expires_at', 'created_at'],
            'invoices' => ['number', 'status', 'due_at', 'currency_code', 'user_id', 'created_at', 'updated_at'],
            'invoice_items' => ['invoice_id', 'price', 'quantity', 'description', 'reference_type', 'reference_id'],
            'invoice_transactions' => ['invoice_id', 'gateway_id', 'amount', 'fee', 'transaction_id', 'status', 'is_credit_transaction', 'created_at'],
            'tickets' => ['subject', 'status', 'priority', 'department', 'user_id', 'service_id', 'created_at', 'updated_at'],
            'ticket_messages' => ['ticket_id', 'user_id', 'message', 'created_at'],
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
