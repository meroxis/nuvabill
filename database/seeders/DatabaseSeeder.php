<?php

namespace Database\Seeders;

use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Demo data for local development: php artisan migrate:fresh --seed
 *
 * Staff sign-in: admin@nuvabill.test / nuvabill-demo
 * Client sign-in: client@nuvabill.test / nuvabill-demo
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DefaultDataSeeder::class);
        (new DemoCatalogSeeder)->run('USD');

        app(Settings::class)->setMany([
            'company.name' => 'YourHost',
            'company.email' => 'billing@nuvabill.test',
            'automation.last_run_at' => now()->toIso8601String(),
        ]);

        Admin::query()->firstOrCreate(['email' => 'admin@nuvabill.test'], [
            'name' => 'Aram Rostami',
            'password' => 'nuvabill-demo',
            'role_id' => Role::query()->where('name', 'Owner')->value('id'),
            'is_active' => true,
        ]);

        $products = Product::query()->with('prices')->get();
        $departments = TicketDepartment::all();

        $demo = Client::factory()->create([
            'first_name' => 'Dana',
            'last_name' => 'Baker',
            'email' => 'client@nuvabill.test',
            'password' => 'nuvabill-demo',
            'company_name' => 'Dana\'s Bakery',
        ]);

        $clients = collect([$demo])->merge(Client::factory()->count(14)->create());

        foreach ($clients as $index => $client) {
            $product = $products[$index % $products->count()];
            $price = $product->priceFor('USD', BillingCycle::Monthly)->price;
            $started = CarbonImmutable::today()->subMonths(11 - ($index % 11))->subDays($index);

            $service = Service::factory()->create([
                'client_id' => $client->id,
                'product_id' => $product->id,
                'domain' => $index === 0 ? 'danasbakery.test' : fake()->unique()->domainWord().'.test',
                'username' => 'user'.($index + 100),
                'recurring_amount' => $price,
                'first_payment_amount' => $price,
                'registration_date' => $started,
                'next_due_date' => CarbonImmutable::today()->addDays(($index * 3) % 28 - 3),
            ]);

            for ($month = $started; $month->lt(CarbonImmutable::today()->startOfMonth()); $month = $month->addMonth()) {
                $invoice = Invoice::factory()->paid()->create([
                    'client_id' => $client->id,
                    'subtotal' => $price,
                    'total' => $price,
                    'amount_paid' => $price,
                    'issued_at' => $month,
                    'due_at' => $month,
                    'paid_at' => $month->addDays(1),
                ]);
                $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => $product->name.' - '.$service->domain, 'amount' => $price, 'period_start' => $month]);
                $invoice->transactions()->create([
                    'client_id' => $client->id,
                    'gateway' => $index % 3 === 0 ? 'paypal' : 'stripe',
                    'reference' => 'demo_'.$invoice->id,
                    'amount' => $price,
                    'currency' => 'USD',
                    'paid_at' => $month->addDays(1),
                ]);
            }
        }

        foreach ($clients->take(4) as $index => $client) {
            $service = $client->services()->first();
            $invoice = Invoice::factory()->create([
                'client_id' => $client->id,
                'subtotal' => $service->recurring_amount,
                'total' => $service->recurring_amount,
                'due_at' => today()->addDays(4 - $index * 3),
                'issued_at' => today()->subDays(3 + $index),
            ]);
            $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => $service->product->name.' renewal', 'amount' => $service->recurring_amount, 'period_start' => $service->next_due_date]);
        }

        $clients[5]->services()->first()->update(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDay(), 'suspension_reason' => 'Overdue on payment']);

        $pending = Order::factory()->create(['client_id' => $clients[6]->id, 'status' => OrderStatus::Pending, 'total' => 899]);
        Service::factory()->pending()->create(['client_id' => $clients[6]->id, 'order_id' => $pending->id, 'product_id' => $products[1]->id, 'recurring_amount' => 899, 'registration_date' => today(), 'next_due_date' => today()]);

        $questions = [
            ['SSL not working on my site', 'My site shows "Not secure" since this morning. Can you check?', TicketStatus::Open],
            ['Upgrade to a bigger plan', 'My shop is growing. Can I move to Business without losing data?', TicketStatus::CustomerReply],
            ['Invoice paid twice', 'I think I paid my last invoice twice, by card and by PayPal.', TicketStatus::Answered],
        ];

        foreach ($questions as $index => [$subject, $message, $status]) {
            $ticket = Ticket::factory()->create([
                'client_id' => $clients[$index + 1]->id,
                'ticket_department_id' => $departments[$index % $departments->count()]->id,
                'subject' => $subject,
                'status' => $status,
                'last_reply_at' => now()->subMinutes(20 * ($index + 1)),
            ]);
            $ticket->replies()->create(['author_type' => 'client', 'author_id' => $ticket->client_id, 'message' => $message]);
        }
    }
}
