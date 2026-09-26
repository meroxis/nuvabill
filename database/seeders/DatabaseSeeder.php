<?php

namespace Database\Seeders;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Activity;
use App\Support\Demo;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Demo data for local development, screenshots and the public demo: php artisan migrate:fresh --seed
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
        $this->vpsPlans();

        app(Settings::class)->setMany([
            'company.name' => 'YourHost',
            'company.email' => 'billing@nuvabill.test',
            'company.address' => "12 Cloud Street\nErbil",
            'automation.last_run_at' => now()->toIso8601String(),
        ]);

        $admin = Admin::query()->firstOrCreate(['email' => Demo::ADMIN_EMAIL], [
            'name' => 'Aram Rostami',
            'password' => Demo::PASSWORD,
            'role_id' => Role::query()->where('name', 'Owner')->value('id'),
            'is_active' => true,
        ]);

        $products = Product::query()->with('prices')->get()->values();
        $today = CarbonImmutable::today();

        $demo = Client::factory()->create([
            'first_name' => 'Dana',
            'last_name' => 'Baker',
            'email' => Demo::CLIENT_EMAIL,
            'password' => Demo::PASSWORD,
            'company_name' => 'Dana\'s Bakery',
        ]);

        $clients = collect([$demo])->merge(Client::factory()->count(35)->create())->values();

        foreach ($clients as $index => $client) {
            $started = $today->subMonths(11 - (int) floor($index / 3.3))->subDays($index % 9);
            $client->forceFill(['created_at' => $started])->save();

            $plans = $index === 0 ? [0, 3] : [$index % $products->count(), ...($index % 4 === 0 ? [($index + 2) % $products->count()] : [])];

            foreach ($plans as $planIndex => $productIndex) {
                $this->serviceWithHistory($client, $products[$productIndex], $started->addMonths($planIndex * 2), $index === 0 && $planIndex === 0 ? 'danasbakery.com' : null);
            }
        }

        foreach ($clients->take(5) as $index => $client) {
            $service = $client->services()->first();
            $invoice = Invoice::factory()->create([
                'client_id' => $client->id,
                'subtotal' => $service->recurring_amount,
                'total' => $service->recurring_amount,
                'due_at' => $today->addDays(5 - $index * 3),
                'issued_at' => $today->subDays(2 + $index),
            ]);
            $invoice->items()->create([
                'type' => 'service',
                'service_id' => $service->id,
                'description' => $service->product->name.' - '.$service->domain.' (renewal)',
                'amount' => $service->recurring_amount,
                'period_start' => $service->next_due_date,
            ]);
        }

        $clients[7]->services()->first()->update(['status' => ServiceStatus::Suspended, 'suspended_at' => now()->subDay(), 'suspension_reason' => 'Overdue on payment']);

        $pending = Order::factory()->create(['client_id' => $clients[8]->id, 'status' => OrderStatus::Pending, 'total' => 899]);
        Service::factory()->pending()->create([
            'client_id' => $clients[8]->id,
            'order_id' => $pending->id,
            'product_id' => $products[1]->id,
            'recurring_amount' => 899,
            'registration_date' => $today,
            'next_due_date' => $today,
        ]);

        $this->tickets($clients->slice(1, 3)->values(), $admin);

        Invoice::query()->orderBy('id')->each(fn (Invoice $invoice) => $invoice->forceFill(['number' => 'INV-'.str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT)])->save());

        $this->gateways();
        $this->activity($clients, $admin);
    }

    /**
     * Bank transfer and Stripe (with placeholder test keys) so the payment page shows real choices.
     */
    private function gateways(): void
    {
        $extensions = app(ExtensionManager::class);
        $extensions->saveSettings('banktransfer', ['instructions' => "Bank: Demo Bank\nIBAN: GB00 DEMO 0000 0000 0000\nReference: {invoice}"], true);
        $extensions->saveSettings('stripe', ['display_name' => 'Credit or debit card', 'secret_key' => 'sk_test_demo', 'webhook_secret' => 'whsec_demo'], true);
    }

    /**
     * @param  Collection<int, Client>  $clients
     */
    private function activity($clients, Admin $admin): void
    {
        $paid = Invoice::query()->where('status', 'paid')->latest('id')->limit(3)->get();

        $events = [
            [4, 'automation.run', 'Daily automation: Invoices created: 5, reminders: 3, suspended: 1, terminated: 0, failed: 0', null, null],
            [7, 'payment.received', 'Payment of $24.00 received for invoice '.$paid[0]->number.' via stripe', $paid[0], null],
            [12, 'service.created', 'Service #'.$clients[3]->services()->first()->id.' ('.$clients[3]->services()->first()->label().') set up: Account created.', $clients[3]->services()->first(), null],
            [18, 'order.placed', 'Order #'.Order::query()->first()->number.' placed', Order::query()->first(), $clients[8]],
            [26, 'payment.received', 'Payment of $8.99 received for invoice '.$paid[1]->number.' via paypal', $paid[1], null],
            [41, 'ticket.opened', 'Ticket #'.Ticket::query()->first()->number.' opened: SSL not working on my site', Ticket::query()->first(), $clients[1]],
            [55, 'ticket.replied', 'Replied to ticket #'.Ticket::query()->skip(2)->first()->number, Ticket::query()->skip(2)->first(), $admin],
            [70, 'invoice.renewal', 'Renewal invoice '.$paid[2]->number.' created', $paid[2], null],
        ];

        foreach ($events as [$minutesAgo, $action, $description, $subject, $actor]) {
            $entry = Activity::log($action, $description, $subject, actor: $actor);
            $entry->forceFill(['created_at' => now()->subMinutes($minutesAgo)])->save();
        }
    }

    private function vpsPlans(): void
    {
        $group = ProductGroup::query()->firstOrCreate(['slug' => 'cloud-vps'], [
            'name' => 'Cloud VPS',
            'description' => 'NVMe virtual servers with full root access.',
            'is_visible' => true,
            'sort_order' => 1,
        ]);

        foreach ([['VPS 2', 'vps-2', 1200, "2 vCPU\n4 GB RAM\n80 GB NVMe\n2 TB traffic"], ['VPS 4', 'vps-4', 2400, "4 vCPU\n8 GB RAM\n160 GB NVMe\n4 TB traffic"], ['VPS 8', 'vps-8', 4800, "8 vCPU\n16 GB RAM\n320 GB NVMe\n8 TB traffic"]] as $order => [$name, $slug, $price, $features]) {
            $product = Product::query()->firstOrCreate(['slug' => $slug], [
                'product_group_id' => $group->id,
                'name' => $name,
                'type' => ProductType::Server,
                'description' => $features,
                'is_visible' => true,
                'requires_domain' => false,
                'auto_setup' => AutoSetup::Manual,
                'sort_order' => $order,
            ]);

            $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Monthly], ['price' => $price, 'setup_fee' => 0]);
            $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually], ['price' => $price * 10, 'setup_fee' => 0]);
        }
    }

    private function serviceWithHistory(Client $client, Product $product, CarbonImmutable $started, ?string $domain): void
    {
        $today = CarbonImmutable::today();
        $price = $product->priceFor('USD', BillingCycle::Monthly)->price;
        $months = max(0, (int) $started->diffInMonths($today));

        $service = Service::factory()->create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'domain' => $domain ?? ($product->requires_domain ? fake()->unique()->domainWord().'.com' : 'vps'.fake()->unique()->numberBetween(100, 999).'.yourhost.net'),
            'username' => substr((string) preg_replace('/[^a-z]/', '', strtolower($client->last_name)), 0, 6).fake()->numberBetween(10, 99),
            'recurring_amount' => $price,
            'first_payment_amount' => $price,
            'registration_date' => $started,
            'next_due_date' => $started->addMonthsNoOverflow($months + 1),
        ]);
        $service->forceFill(['created_at' => $started])->save();

        for ($month = 0; $month <= $months; $month++) {
            $periodStart = $started->addMonthsNoOverflow($month);

            if ($periodStart->gt($today)) {
                break;
            }

            $paidAt = $periodStart->addDay()->min(now());

            $invoice = Invoice::factory()->paid()->create([
                'client_id' => $client->id,
                'subtotal' => $price,
                'total' => $price,
                'amount_paid' => $price,
                'issued_at' => $periodStart->subDays(7),
                'due_at' => $periodStart,
                'paid_at' => $paidAt,
            ]);
            $invoice->items()->create([
                'type' => 'service',
                'service_id' => $service->id,
                'description' => $product->name.' - '.$service->domain.' ('.$periodStart->format('d M Y').' - '.$periodStart->addMonthNoOverflow()->subDay()->format('d M Y').')',
                'amount' => $price,
                'period_start' => $periodStart,
            ]);
            $invoice->transactions()->create([
                'client_id' => $client->id,
                'gateway' => $client->id % 3 === 0 ? 'paypal' : 'stripe',
                'reference' => 'demo_'.$invoice->id,
                'amount' => $price,
                'currency' => 'USD',
                'paid_at' => $paidAt,
            ]);
        }
    }

    /**
     * @param  Collection<int, Client>  $clients
     */
    private function tickets($clients, Admin $admin): void
    {
        $departments = TicketDepartment::all()->keyBy('name');

        $threads = [
            ['Technical support', 'SSL not working on my site', TicketStatus::Open, [
                ['client', 'My site shows "Not secure" since this morning. I have free SSL with my plan. Customers are leaving, please help fast.'],
            ]],
            ['Sales', 'Upgrade to a bigger plan', TicketStatus::CustomerReply, [
                ['client', 'My shop is growing and the site feels slow at night. Can I move from Starter to Business without losing data?'],
                ['admin', "Hi! Yes, you can upgrade with no data loss. It takes about two minutes and your files and emails stay as they are.\n\nYou only pay the difference for the rest of this month."],
                ['client', 'Great, please do it tonight after 22:00.'],
            ]],
            ['Billing', 'Invoice paid twice', TicketStatus::Answered, [
                ['client', 'I think I paid my last invoice twice, by card and by PayPal. Can you check?'],
                ['admin', "You are right, it was paid twice. I refunded the PayPal payment. You will see it back in 3 to 5 days.\n\nSorry for the trouble!"],
            ]],
        ];

        foreach ($threads as $index => [$department, $subject, $status, $messages]) {
            $client = $clients[$index];
            $ticket = Ticket::factory()->create([
                'client_id' => $client->id,
                'ticket_department_id' => $departments[$department]->id,
                'subject' => $subject,
                'status' => $status,
                'last_reply_at' => now()->subMinutes(25 * ($index + 1)),
            ]);

            foreach ($messages as $position => [$author, $message]) {
                $reply = $ticket->replies()->create([
                    'author_type' => $author,
                    'author_id' => $author === 'admin' ? $admin->id : $client->id,
                    'message' => $message,
                ]);
                $reply->forceFill(['created_at' => now()->subMinutes(25 * ($index + 1) + 60 * (count($messages) - $position))])->save();
            }
        }
    }
}
