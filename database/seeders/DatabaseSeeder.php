<?php

namespace Database\Seeders;

use App\Billing\ExchangeRates;
use App\Billing\QuoteManager;
use App\Billing\Wallet;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\OrderStatus;
use App\Enums\ProductType;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Server;
use App\Models\Service;
use App\Models\TaxRule;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TldPrice;
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
    /**
     * Demo client businesses. People in demo data are only ever called Raz or Mer Las.
     *
     * @var list<string>
     */
    private const COMPANIES = [
        'Bright Pixel Studio', 'Cedar Web Shop', 'Blue River Media', 'Silver Oak Cafe', 'Green Leaf Market',
        'Mountain View Tours', 'Sunrise Bakery', 'Clear Sky Travel', 'Stone Bridge Legal', 'Golden Field Farms',
        'Swift Parcel', 'Harbor Light Design', 'Red Brick Books', 'Open Road Motors', 'Maple Street Dental',
        'North Star Fitness', 'Quiet Lake Hotel', 'Urban Nest Homes', 'Paper Plane Agency', 'Iron Gate Security',
        'Fresh Basket Grocery', 'Bold Ink Printing', 'Little Sprout School', 'Rapid Repair', 'Blue Tile Kitchen',
        'Starlight Events', 'Summit Outdoor', 'Lantern Photo', 'Wild Honey Crafts', 'Nimbus Apps',
        'Coral Bay Diving', 'Pine Hill Clinic', 'Echo Music School', 'Velvet Rose Salon', 'Copper Pot Restaurant',
    ];

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
            'orders.accept_terms_url' => 'https://nuvabill.com/terms/',
            'company.privacy_url' => 'https://nuvabill.com/privacy/',
        ]);

        $admin = Admin::query()->firstOrCreate(['email' => Demo::ADMIN_EMAIL], [
            'name' => 'Mer Las',
            'password' => Demo::PASSWORD,
            'role_id' => Role::query()->where('name', 'Owner')->value('id'),
            'is_active' => true,
        ]);

        $products = Product::query()->with('prices')->get()->values();
        $today = CarbonImmutable::today();

        $demo = Client::factory()->create([
            'first_name' => 'Raz',
            'last_name' => '',
            'email' => Demo::CLIENT_EMAIL,
            'password' => Demo::PASSWORD,
            'company_name' => 'Raz Studio',
        ]);

        $clients = collect([$demo])->merge(collect(self::COMPANIES)->map(fn (string $company, int $index): Client => Client::factory()->create([
            'first_name' => $index % 2 === 0 ? 'Mer' : 'Raz',
            'last_name' => $index % 2 === 0 ? 'Las' : '',
            'company_name' => $company,
            'email' => self::slug($company).'@example.com',
        ])))->values();

        foreach ($clients as $index => $client) {
            $started = $today->subMonths(11 - (int) floor($index / 3.3))->subDays($index % 9);
            $client->forceFill(['created_at' => $started])->save();

            $plans = $index === 0 ? [0, 3] : [$index % $products->count(), ...($index % 4 === 0 ? [($index + 2) % $products->count()] : [])];

            foreach ($plans as $planIndex => $productIndex) {
                $this->serviceWithHistory($client, $products[$productIndex], $started->addMonths($planIndex * 2), $index === 0 && $planIndex === 0 ? 'razstudio.com' : null);
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

        Service::query()
            ->whereHas('product', fn ($query) => $query->where('server_module', 'virtualizor'))
            ->each(fn (Service $service) => $service->update([
                'server_id' => Server::query()->where('hostname', Demo::VPS_HOST)->value('id'),
                'username' => 'root',
                'domain' => 'vps'.(100 + $service->id).'.yourhost.net',
                'module_data' => ['vpsid' => (string) (100 + $service->id)],
            ]));

        $this->domains($clients);
        $this->tickets($clients->slice(1, 3)->values(), $admin);

        Invoice::query()->orderBy('id')->each(fn (Invoice $invoice) => $invoice->forceFill(['number' => 'INV-'.str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT)])->save());

        $this->gateways();
        $this->addonsAndCoupons();
        $this->billingExtras($demo, $clients, $admin);
        $this->activity($clients, $admin);
    }

    /**
     * A tax rule, wallet credit, quotes and an affiliate with commissions, so those pages have something to show.
     *
     * @param  Collection<int, Client>  $clients
     */
    private function billingExtras(Client $demo, Collection $clients, Admin $admin): void
    {
        app(Settings::class)->setMany(['tax.enabled' => true, 'affiliates.enabled' => true]);
        TaxRule::query()->firstOrCreate(['name' => 'VAT', 'country' => null], ['rate' => 500]);

        $wallet = app(Wallet::class);
        $wallet->change($demo, 2500, 'Welcome credit', admin: $admin);
        $wallet->change($clients[2], 1000, 'Refund for downtime', admin: $admin);

        $quotes = app(QuoteManager::class);
        $sent = $quotes->save(null, $demo, [
            'subject' => 'Dedicated server with managed backups',
            'valid_until' => CarbonImmutable::today()->addDays(30),
            'notes' => 'Ready within two working days. Monitoring is included.',
        ], [
            ['description' => 'Dedicated server, 64 GB RAM, 2 x 1 TB NVMe (first month)', 'amount' => 14900],
            ['description' => 'Managed backups, 30 days of copies', 'amount' => 2500],
            ['description' => 'Setup and move of your current sites', 'amount' => 9900],
        ]);
        $sent->forceFill(['number' => 'Q-'.str_pad((string) $sent->id, 4, '0', STR_PAD_LEFT), 'status' => QuoteStatus::Sent, 'sent_at' => now()->subDays(2)])->save();

        $quotes->save(null, $clients[4], [
            'subject' => 'Move three websites from another host',
            'valid_until' => CarbonImmutable::today()->addDays(30),
        ], [['description' => 'Move 3 websites and 12 mailboxes', 'amount' => 7500]]);

        $affiliate = Affiliate::query()->create(['client_id' => $demo->id, 'code' => 'RAZ', 'status' => Affiliate::STATUS_ACTIVE, 'clicks' => 148]);

        foreach ($clients->slice(10, 3)->values() as $index => $referred) {
            $affiliate->referrals()->create(['client_id' => $referred->id]);
            $invoice = $referred->invoices()->where('status', 'paid')->oldest('id')->first();

            if ($invoice !== null) {
                $affiliate->commissions()->create([
                    'client_id' => $referred->id,
                    'invoice_id' => $invoice->id,
                    'amount' => (int) round($invoice->subtotal * 0.1),
                    'currency' => $invoice->currency,
                    'status' => $index < 2 ? AffiliateCommission::STATUS_AVAILABLE : AffiliateCommission::STATUS_PENDING,
                    'available_at' => $index < 2 ? CarbonImmutable::today()->subDays(3) : CarbonImmutable::today()->addDays(12),
                ]);
            }
        }
    }

    /**
     * Add-ons for the order form, a welcome coupon, and a dinar rate so Iraqi gateways can charge dollar invoices.
     */
    private function addonsAndCoupons(): void
    {
        $hosting = Product::query()->where('server_module', '!=', 'virtualizor')->orWhereNull('server_module')->pluck('id')->all();

        foreach ([
            ['Daily backups', 'Keep 30 days of copies. Restore any file in one click.', 'refresh', 200, true, $hosting],
            ['Priority support', 'Answers in under one hour, every day.', 'zap', 500, false, null],
            ['Dedicated IP address', 'Your own IP address for your site or server.', 'globe', 300, false, null],
        ] as $order => [$name, $description, $icon, $monthly, $popular, $products]) {
            $addon = ProductAddon::query()->firstOrCreate(['name' => $name], [
                'description' => $description,
                'icon' => $icon,
                'product_ids' => $products,
                'is_visible' => true,
                'is_popular' => $popular,
                'sort_order' => $order,
            ]);

            $addon->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Monthly], ['price' => $monthly]);
            $addon->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually], ['price' => $monthly * 10]);
        }

        Coupon::query()->firstOrCreate(['code' => 'WELCOME20'], [
            'type' => Coupon::TYPE_PERCENT,
            'value' => 20,
            'recurring' => Coupon::RECURRING_FIRST,
            'new_clients_only' => true,
            'is_active' => true,
            'notes' => 'Demo coupon: 20% off the first payment for new clients.',
        ]);

        Coupon::query()->firstOrCreate(['code' => 'YEARLY15'], [
            'type' => Coupon::TYPE_PERCENT,
            'value' => 15,
            'billing_cycles' => [BillingCycle::Annually->value, BillingCycle::Biennially->value],
            'recurring' => Coupon::RECURRING_EVERY,
            'is_active' => true,
        ]);

        app(ExchangeRates::class)->save(['IQD' => 1310]);
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

    /**
     * VPS plans on the demo Virtualizor node (answered by Demo::fakeServers()), so clients see the VPS panel.
     */
    private function vpsPlans(): void
    {
        $server = Server::query()->firstOrCreate(['hostname' => Demo::VPS_HOST], [
            'name' => 'VPS node 1',
            'module' => 'virtualizor',
            'port' => 4085,
            'use_ssl' => true,
            'username' => 'admin',
            'api_token' => 'demo-api-key',
            'password' => 'demo-api-pass',
            'is_active' => true,
        ]);

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
                'server_module' => 'virtualizor',
                'server_id' => $server->id,
                'module_config' => ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => (string) ($order + 1)],
                'auto_setup' => AutoSetup::Manual,
                'sort_order' => $order,
            ]);

            $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Monthly], ['price' => $price, 'setup_fee' => 0]);
            $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually], ['price' => $price * 10, 'setup_fee' => 0]);
        }
    }

    /**
     * Domain prices for the store's domain search, and domains for some clients.
     *
     * @param  Collection<int, Client>  $clients
     */
    private function domains($clients): void
    {
        $prices = [
            ['com', 1199, 1199, 1499, true],
            ['net', 1399, 1399, 1699, true],
            ['org', 1299, 1299, 1599, true],
            ['io', 3999, 3999, 4499, true],
            ['co', 2499, 2499, 2999, false],
            ['dev', 1599, 1599, 1799, false],
            ['app', 1799, 1799, 1999, false],
            ['xyz', 299, 1299, 1499, false],
        ];

        foreach ($prices as $order => [$tld, $register, $transfer, $renew, $featured]) {
            TldPrice::query()->firstOrCreate(['tld' => $tld, 'currency' => 'USD'], [
                'register_price' => $register,
                'transfer_price' => $transfer,
                'renew_price' => $renew,
                'epp_required' => $tld !== 'xyz',
                'is_featured' => $featured,
                'is_enabled' => true,
                'sort_order' => $order,
            ]);
        }

        $today = CarbonImmutable::today();

        Domain::factory()->create([
            'client_id' => $clients[0]->id,
            'name' => 'razstudio.com',
            'tld' => 'com',
            'registered_at' => $today->subMonths(10),
            'expires_at' => $today->addMonths(2),
            'next_due_date' => $today->addMonths(2),
            'recurring_amount' => 1499,
            'nameservers' => ['ns1.yourhost.net', 'ns2.yourhost.net'],
        ]);

        Domain::factory()->create([
            'client_id' => $clients[0]->id,
            'name' => 'razstudio.net',
            'tld' => 'net',
            'registered_at' => $today->subYears(2)->addDays(20),
            'expires_at' => $today->addDays(20),
            'next_due_date' => $today->addDays(20),
            'recurring_amount' => 1699,
            'nameservers' => ['ns1.yourhost.net', 'ns2.yourhost.net'],
        ]);

        foreach ($clients->slice(1, 12)->values() as $index => $client) {
            $tld = ['com', 'net', 'org', 'io'][$index % 4];
            $expires = $today->addDays(15 + $index * 23);

            Domain::factory()->create([
                'client_id' => $client->id,
                'name' => self::slug((string) $client->company_name).'.'.$tld,
                'tld' => $tld,
                'registered_at' => $expires->subYear(),
                'expires_at' => $expires,
                'next_due_date' => $expires,
                'recurring_amount' => collect($prices)->firstWhere(0, $tld)[3],
                'nameservers' => ['ns1.yourhost.net', 'ns2.yourhost.net'],
            ]);
        }
    }

    private function serviceWithHistory(Client $client, Product $product, CarbonImmutable $started, ?string $domain): void
    {
        $today = CarbonImmutable::today();
        $price = $product->priceFor('USD', BillingCycle::Monthly)->price;
        $months = max(0, (int) $started->diffInMonths($today));

        $slug = self::slug((string) $client->company_name);
        $siteDomain = $client->services()->exists() ? $slug.'-shop.com' : $slug.'.com';

        $service = Service::factory()->create([
            'client_id' => $client->id,
            'product_id' => $product->id,
            'domain' => $domain ?? ($product->requires_domain ? $siteDomain : 'vps'.fake()->unique()->numberBetween(100, 999).'.yourhost.net'),
            'username' => substr($slug, 0, 6).fake()->numberBetween(10, 99),
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
     * "Bright Pixel Studio" => "brightpixelstudio", for demo emails, domains and usernames.
     */
    private static function slug(string $company): string
    {
        return (string) preg_replace('/[^a-z0-9]/', '', strtolower($company));
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
