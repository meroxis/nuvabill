<?php

namespace Database\Seeders;

use App\Automations\Runner;
use App\Automations\Templates;
use App\Billing\ExchangeRates;
use App\Billing\QuoteManager;
use App\Billing\Wallet;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\IncidentStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MachineStatus;
use App\Enums\OrderStatus;
use App\Enums\PowerState;
use App\Enums\ProductType;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Enums\TicketStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Models\Admin;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\Announcement;
use App\Models\Automation;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\CreditNote;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\KbArticle;
use App\Models\KbCategory;
use App\Models\Machine;
use App\Models\MachineIp;
use App\Models\NetworkIncident;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\ProductGroup;
use App\Models\Role;
use App\Models\Server;
use App\Models\ServerCheck;
use App\Models\Service;
use App\Models\ServiceResourceOption;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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

    /**
     * Set while "nuvabill:demo-reset --force" runs: the one deliberate way to load the demo data on
     * a site that is not the demo.
     */
    private static bool $forced = false;

    /**
     * Run $load with the demo data allowed on this site, whatever its mode.
     *
     * @template T
     *
     * @param  callable(): T  $load
     * @return T
     */
    public static function forced(callable $load): mixed
    {
        $before = self::$forced;
        self::$forced = true;

        try {
            return $load();
        } finally {
            self::$forced = $before;
        }
    }

    public function run(): void
    {
        $this->refuseOnRealSites();

        // The demo reset moves to a new database in the same process, so extension rows cached from
        // the old database are dropped first. Saving over a stale row could leave an extension off.
        app(ExtensionManager::class)->refresh();

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
                'billing_key' => 'service-'.$service->id.'-'.$service->next_due_date->toDateString(),
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

        // Demo::fakeServers() gives each demo VPS its own Virtualizor user with the same number.
        Service::query()
            ->whereHas('product', fn ($query) => $query->where('server_module', 'virtualizor'))
            ->each(fn (Service $service) => $service->update([
                'server_id' => Server::query()->where('hostname', Demo::VPS_HOST)->value('id'),
                'username' => 'root',
                'domain' => 'vps'.(100 + $service->id).'.yourhost.net',
                'module_data' => ['vpsid' => (string) (100 + $service->id), 'uid' => (string) (100 + $service->id)],
            ]));

        $this->extraRam($clients[5]);
        $this->dedicatedServers($clients[5]);
        $this->domains($clients);
        $this->tickets($clients->slice(1, 4)->values(), $admin);

        Invoice::query()->orderBy('id')->each(fn (Invoice $invoice) => $invoice->forceFill(['number' => 'INV-'.str_pad((string) $invoice->id, 4, '0', STR_PAD_LEFT)])->save());

        $this->gateways();
        $this->addonsAndCoupons();
        $this->billingExtras($demo, $clients, $admin);
        $this->activity($clients, $admin);
        $this->automations($clients);
        $this->savedPaymentMethods($clients);
        $this->helpCenter($admin);
        $this->creditNote($demo, $admin);
        $this->demoAddons();
    }

    /**
     * On the public demo, switch on the add-ons in NUVABILL_DEMO_ADDONS again, such as Dedicated
     * Server Power for the power buttons of the demo's dedicated servers. Only add-ons already in
     * the extensions folder: nothing is downloaded, and no marketplace install or license is made,
     * so a demo copy of a paid add-on never counts as licensed anywhere. An add-on's own tables
     * are made again, as an install from the marketplace makes them, and only when it asks for
     * the database permission.
     */
    private function demoAddons(): void
    {
        $extensions = app(ExtensionManager::class);

        foreach (Demo::addons() as $slug) {
            $manifest = $extensions->find($slug);

            if ($manifest === null || $manifest->type !== ExtensionManifest::TYPE_ADDON) {
                $this->warn("The demo add-on {$slug} is not in the extensions folder, so it was not switched on.");

                continue;
            }

            $migrations = $manifest->path.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';

            $hasMigrations = is_dir($migrations) && (glob($migrations.DIRECTORY_SEPARATOR.'*.php') ?: []) !== [];

            if ($hasMigrations && ! in_array('database', $manifest->permissions, true)) {
                $this->warn("The demo add-on {$slug} changes the database but does not ask for the database permission, so it was not switched on.");

                continue;
            }

            // A broken add-on is reported and left off, and the reset carries on with the others, so
            // one bad migration never stops the demo from getting fresh data.
            try {
                if ($hasMigrations && Artisan::call('migrate', ['--path' => $migrations, '--realpath' => true, '--force' => true]) !== 0) {
                    throw new RuntimeException("The migrations of the demo add-on [{$slug}] did not finish.");
                }

                // A broken demo hook is reported and the add-on is switched on with its defaults.
                $extensions->saveSettings($slug, rescue(fn (): array => $extensions->addon($slug)->demoSettings(), []), true);
            } catch (Throwable $exception) {
                report($exception);
                $this->warn("The demo add-on {$slug} could not be switched on.");
            }
        }
    }

    private function warn(string $message): void
    {
        Log::warning($message);
        $this->command?->warn($message);
    }

    /**
     * A credit note for an hour of downtime on one of the demo client's paid invoices, added to their wallet.
     */
    private function creditNote(Client $demo, Admin $admin): void
    {
        $invoice = $demo->invoices()->where('status', InvoiceStatus::Paid)->where('total', '>=', 300)->latest('id')->first();

        if ($invoice === null) {
            return;
        }

        $creditNote = CreditNote::query()->create([
            'invoice_id' => $invoice->id,
            'client_id' => $demo->id,
            'admin_id' => $admin->id,
            'currency' => $invoice->currency,
            'subtotal' => 200,
            'tax' => 0,
            'total' => 200,
            'items' => [['description' => 'Credit for invoice '.$invoice->number.': one hour of downtime', 'amount' => 200]],
            'method' => CreditNote::METHOD_WALLET,
            'reason' => 'One hour of downtime',
            'issued_at' => now()->subDays(2),
        ]);
        $creditNote->forceFill(['number' => 'CN-'.str_pad((string) $creditNote->id, 4, '0', STR_PAD_LEFT)])->save();
        app(Wallet::class)->change($demo, 200, 'Credit note '.$creditNote->number, $invoice, $admin);
    }

    /**
     * Saved cards and a PayPal account, one renewal charged automatically soon and one whose
     * charge failed and waits for the next try, so automatic payments have something to show.
     *
     * @param  Collection<int, Client>  $clients
     */
    private function savedPaymentMethods(Collection $clients): void
    {
        $methods = [
            0 => ['type' => PaymentMethod::TYPE_CARD, 'brand' => 'visa', 'last4' => '4242', 'expires_month' => 8, 'expires_year' => (int) now()->addYears(2)->format('Y')],
            1 => ['gateway' => 'paypal', 'type' => PaymentMethod::TYPE_PAYPAL, 'email' => $clients[1]->email],
            3 => ['type' => PaymentMethod::TYPE_CARD, 'brand' => 'mastercard', 'last4' => '5100', 'expires_month' => 1, 'expires_year' => (int) now()->addYear()->format('Y')],
        ];

        foreach ($methods as $index => $method) {
            PaymentMethod::query()->create($method + [
                'client_id' => $clients[$index]->id,
                'gateway' => 'stripe',
                'reference' => 'demo_'.$index,
                'is_default' => true,
                'last_used_at' => now()->subMonth(),
            ]);
        }

        $clients[1]->invoices()->where('status', InvoiceStatus::Unpaid)->oldest('id')->first()?->update(['due_at' => today()->addDay()]);
        $clients[3]->invoices()->where('status', InvoiceStatus::Unpaid)->oldest('id')->first()?->forceFill([
            'autopay_attempts' => 2,
            'autopay_retry_at' => today()->addDays(3),
            'autopay_error' => 'Your card was declined.',
        ])->save();
    }

    /**
     * A few automations from the templates, some on, and two VIP clients, so the Automations page has something to show.
     *
     * @param  Collection<int, Client>  $clients
     */
    private function automations(Collection $clients): void
    {
        foreach ($clients->take(2) as $client) {
            $client->forceFill(['tags' => ['VIP']])->save();
        }

        foreach (['late-fee' => true, 'welcome' => true, 'big-order' => true, 'vip-tickets' => true, 'win-back' => false] as $key => $active) {
            $template = Templates::find($key);

            Automation::query()->create([
                'name' => $template['name'],
                'trigger' => $template['trigger'],
                'trigger_days' => $template['days'],
                'conditions' => $template['conditions'],
                'steps' => $template['steps'],
                'is_active' => $active,
                'template' => $key,
            ]);
        }

        app(Runner::class)->scan(CarbonImmutable::today());
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
        $hosting = Product::query()->whereNotIn('server_module', ['virtualizor', 'dedicated'])->orWhereNull('server_module')->pluck('id')->all();

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
        $extensions->saveSettings('paypal', ['mode' => 'sandbox', 'client_id' => 'demo', 'client_secret' => 'demo'], true);
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
     * Knowledge base articles, announcements, and a network status page with 30 days of checks for
     * the VPS node, a fixed issue and maintenance planned in three days.
     */
    private function helpCenter(Admin $admin): void
    {
        $articles = [
            ['Getting started', 'First steps with your new service.', [
                ['How do I sign in to my control panel?', "Open **Services** in the client area and pick your service.\n\nClick **Open control panel**. You are signed in without a password.\n\nIf the button is missing, your service is still being set up. It usually takes a few minutes."],
                ['How do I point my domain to your servers?', "Change the nameservers at the company where you bought the domain to:\n\n- `ns1.yourhost.net`\n- `ns2.yourhost.net`\n\nThe change can take up to 24 hours to reach everyone. Domains you bought from us point here already."],
            ]],
            ['Email', 'Email accounts, phones and spam.', [
                ['How do I set up email on my phone?', "Use these settings in the mail app on your phone:\n\n| Setting | Value |\n|---|---|\n| Incoming server | `mail.yourdomain.com`, port 993, SSL |\n| Outgoing server | `mail.yourdomain.com`, port 465, SSL |\n| User name | your full email address |\n\nThe password is the one you gave the email account in the control panel."],
                ['Why do my emails go to spam?', "Most of the time a DNS record is missing. In the control panel, open **Email Deliverability** and fix the records it marks.\n\nAlso check that you do not send to old lists, and that each email has a way to unsubscribe."],
            ]],
            ['Billing', 'Invoices, payments and plan changes.', [
                ['How do I pay an invoice?', "Open **Billing** in the client area, pick the invoice and click **Pay now**.\n\nYou can pay by card, PayPal, bank transfer, or from your wallet if it has credit."],
                ['How do I upgrade or downgrade my plan?', "Open the service and click **Upgrade or downgrade**. Pick the new plan.\n\nYou only pay the difference for the days left until the next due date. A smaller plan gives you credit for those days."],
                ['How do automatic payments work?', "Save a card or PayPal account under **Payment methods**. Renewal invoices are then paid on their due date.\n\nIf a payment fails, we try again and email you."],
            ]],
        ];

        foreach ($articles as $order => [$name, $description, $items]) {
            $category = KbCategory::query()->create(['name' => $name, 'slug' => str($name)->slug()->toString(), 'description' => $description, 'sort_order' => $order]);

            foreach ($items as $index => [$title, $body]) {
                KbArticle::query()->create([
                    'kb_category_id' => $category->id,
                    'title' => $title,
                    'slug' => str($title)->slug()->toString(),
                    'body' => $body,
                    'sort_order' => $index,
                ])->forceFill(['helpful_yes' => 12 + $index * 7 + $order * 3, 'helpful_no' => $index + 1])->save();
            }
        }

        KbArticle::query()->where('slug', 'how-do-i-pay-an-invoice')->first()?->saveTranslation('de', 'Wie bezahle ich eine Rechnung?',
            "Öffnen Sie **Rechnungen** im Kundenbereich, wählen Sie die Rechnung und klicken Sie auf **Jetzt bezahlen**.\n\nSie können per Karte, PayPal, Überweisung oder mit Guthaben aus Ihrer Geldbörse bezahlen.");
        KbArticle::query()->where('slug', 'how-do-i-pay-an-invoice')->first()?->saveTranslation('ar', 'كيف أدفع فاتورة؟',
            "افتح **الفواتير** في منطقة العميل، واختر الفاتورة ثم انقر **ادفع الآن**.\n\nيمكنك الدفع بالبطاقة أو PayPal أو التحويل البنكي أو من رصيد محفظتك.");

        foreach ([
            [3, 'New Cloud VPS plans with NVMe disks', "Our new **Cloud VPS** plans run on NVMe disks, up to five times faster than before.\n\n- VPS 2: 2 vCPU, 4 GB RAM\n- VPS 4: 4 vCPU, 8 GB RAM\n- VPS 8: 8 vCPU, 16 GB RAM\n\nYou can move to a new plan from your service page. You only pay the difference."],
            [1, 'Support now answers around the clock', "Our support team now answers tickets **24 hours a day, 7 days a week**.\n\nMany answers are also in our knowledge base."],
            [10, 'Emails now arrive in your language', 'Invoices, reminders and ticket replies now come in the language you picked in the client area.'],
        ] as [$daysAgo, $title, $body]) {
            Announcement::query()->create(['title' => $title, 'slug' => str($title)->slug()->toString(), 'body' => $body, 'published_at' => now()->subDays($daysAgo)->setTime(9, 30)]);
        }

        $server = Server::query()->where('hostname', Demo::VPS_HOST)->firstOrFail();
        $server->forceFill(['status_public' => true, 'status_name' => 'Cloud VPS', 'status_up' => true, 'status_checked_at' => now(), 'status_changed_at' => now()->subDays(5)])->save();
        $rows = [];

        // One check an hour for 30 days, with a short outage five days ago.
        for ($hour = 30 * 24; $hour >= 0; $hour--) {
            $rows[] = ['server_id' => $server->id, 'is_up' => ! in_array($hour, [5 * 24 + 3, 5 * 24 + 4], true), 'response_ms' => 20 + ($hour * 7) % 30, 'checked_at' => now()->subHours($hour)];
        }

        foreach (array_chunk($rows, 250) as $chunk) {
            ServerCheck::query()->insert($chunk);
        }

        $issue = NetworkIncident::query()->create(['title' => 'Slow email delivery', 'kind' => NetworkIncident::KIND_ISSUE, 'status' => IncidentStatus::Resolved, 'impact' => NetworkIncident::IMPACT_MINOR, 'starts_at' => now()->subDays(3)->setTime(14, 10), 'resolved_at' => now()->subDays(3)->setTime(15, 40)]);

        foreach ([[IncidentStatus::Investigating, 'Some emails arrive late. We are looking into it.', 0], [IncidentStatus::Identified, 'A mail queue was full. We are clearing it now.', 35], [IncidentStatus::Resolved, 'All late emails are delivered. Sorry for the wait.', 90]] as [$status, $message, $minutes]) {
            $issue->updates()->create(['admin_id' => $admin->id, 'status' => $status, 'message' => $message])
                ->forceFill(['created_at' => $issue->starts_at->copy()->addMinutes($minutes)])->save();
        }

        $work = NetworkIncident::query()->create(['title' => 'Network upgrade for VPS node 1', 'kind' => NetworkIncident::KIND_MAINTENANCE, 'status' => IncidentStatus::Scheduled, 'impact' => NetworkIncident::IMPACT_MINOR, 'server_ids' => [$server->id], 'starts_at' => now()->addDays(3)->setTime(2, 0), 'ends_at' => now()->addDays(3)->setTime(3, 0)]);
        $work->updates()->create(['admin_id' => $admin->id, 'status' => IncidentStatus::Scheduled, 'message' => 'We connect the node to a faster network. Servers may be offline for up to 10 minutes.']);
    }

    /**
     * VPS plans on the demo Virtualizor node (answered by Demo::fakeServers()), so clients see the VPS panel
     * with every tab the fake node answers for, and sliders for extra CPU, RAM, disk and IPv4.
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

        foreach ([['VPS 2', 'vps-2', 1200, "2 vCPU\n4 GB RAM\n80 GB NVMe\n2 TB traffic", [2, 4096, 80]], ['VPS 4', 'vps-4', 2400, "4 vCPU\n8 GB RAM\n160 GB NVMe\n4 TB traffic", [4, 8192, 160]], ['VPS 8', 'vps-8', 4800, "8 vCPU\n16 GB RAM\n320 GB NVMe\n8 TB traffic", [8, 16384, 320]]] as $order => [$name, $slug, $price, $features, $plan]) {
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
                // Off by default until tested on a real Virtualizor; the demo node answers for them.
                'panel_sections' => ['firewall' => true, 'backups' => true, 'media' => true],
                'auto_setup' => AutoSetup::Manual,
                'sort_order' => $order,
            ]);

            $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Monthly], ['price' => $price, 'setup_fee' => 0]);
            $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually], ['price' => $price * 10, 'setup_fee' => 0]);
            $this->resourceOptions($product, ...$plan);
        }
    }

    /**
     * Sliders for extra CPU cores, RAM, disk space and IPv4 addresses on a VPS plan, priced per
     * step in dollars, with the module's kind details saved on each option as the option editor
     * saves them. Disk can only grow; an extra IPv4 address has a one-time setup fee.
     */
    private function resourceOptions(Product $product, int $cores, int $ramMb, int $diskGb): void
    {
        $options = [
            // kind, unit, step size, plan amount, most steps, monthly price per step, setup fee per step, can shrink, restarts, note, module maximum
            ['cpu', 'core', 1, $cores, 8, 400, 0, true, true, null, 64],
            ['ram', 'MB', 1024, $ramMb, 16, 300, 0, true, true, null, 262144],
            ['disk', 'GB', 20, $diskGb, 10, 200, 0, false, false, 'Grow the partition inside your server to use the new space.', 16384],
            ['ipv4', 'IP', 1, 1, 4, 250, 200, true, false, 'New addresses may need a restart.', 16],
        ];

        foreach ($options as $order => [$kind, $unit, $step, $included, $maxSteps, $monthly, $setup, $canShrink, $restarts, $note, $maxExtra]) {
            $option = $product->resourceOptions()->firstOrCreate(['kind' => $kind], [
                'unit' => $unit,
                'step_size' => $step,
                'included' => $included,
                'min_steps' => 0,
                'max_steps' => $maxSteps,
                'default_steps' => 0,
                'client_can_add' => true,
                'client_can_remove' => $canShrink,
                'is_active' => true,
                'sort_order' => $order,
                'can_shrink' => $canShrink,
                'restarts' => $restarts,
                'note' => $note,
                'max_extra' => $maxExtra,
            ]);

            foreach ([[BillingCycle::Monthly, 1], [BillingCycle::Annually, 10]] as [$cycle, $months]) {
                $option->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => $cycle], ['price' => $monthly * $months, 'setup_fee' => $setup]);
            }
        }
    }

    /**
     * One more RAM step on a "Mer Las" VPS, so staff see a service with extras: its own row, at the
     * price of one step, renewed with the plan.
     */
    private function extraRam(Client $client): void
    {
        $service = $client->services()
            ->whereHas('product', fn ($query) => $query->where('server_module', 'virtualizor'))
            ->with('product.resourceOptions.prices')
            ->first();
        $option = $service?->product->resourceOptions->firstWhere('kind', 'ram');

        if ($service === null || $option === null) {
            return;
        }

        $service->resources()->create([
            'resource_option_id' => $option->id,
            'kind' => $option->kind,
            'unit' => $option->unit,
            'step_size' => $option->step_size,
            'steps' => 1,
            'recurring_amount' => (int) $option->priceFor($service->currency, $service->billing_cycle)?->price,
            'status' => ServiceResourceOption::STATUS_ACTIVE,
        ]);
    }

    /**
     * Dedicated servers from the hardware inventory: one product sold from six machines whose
     * BMCs are fakes (Demo::fakeServers() answers for them). DS-101 runs a "Mer Las" server,
     * DS-103 waits for its disks to be wiped, and DS-102, DS-104, DS-105 and DS-106 are free stock,
     * so one visitor's order does not sell the product out for everyone until the next reset.
     *
     * The BMC passwords are random and the certificate pins are dummies: the fake BMCs check
     * neither, and the demo locks every hardware change.
     */
    private function dedicatedServers(Client $client): void
    {
        $group = ProductGroup::query()->firstOrCreate(['slug' => 'dedicated-servers'], [
            'name' => 'Dedicated servers',
            'description' => 'Your own physical server in our rack, with full root access and power buttons in your client area.',
            'is_visible' => true,
            'sort_order' => 2,
        ]);

        $product = Product::query()->firstOrCreate(['slug' => 'dedicated-e-2388g'], [
            'product_group_id' => $group->id,
            'name' => 'Dedicated E-2388G',
            'type' => ProductType::Dedicated,
            'description' => "Intel Xeon E-2388G, 8 cores\n64 GB ECC RAM\n2 × 960 GB NVMe\n1 Gbps port\nPower on, off and restart from your client area",
            'is_visible' => true,
            'requires_domain' => false,
            'server_module' => 'dedicated',
            'uses_machines' => true,
            'module_config' => [
                'client_power' => 'full',
                'reinstall_requests' => '1',
                'suspend_mode' => 'graceful',
                'suspend_notify_staff' => '0',
                'unsuspend_power_on' => '1',
                'setup_before_payment' => '0',
                'default_os' => 'Debian 12',
                'login_name' => 'root',
            ],
            'auto_setup' => AutoSetup::OnPayment,
            'sort_order' => 0,
        ]);

        $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Monthly], ['price' => 9900, 'setup_fee' => 0]);
        $product->prices()->firstOrCreate(['currency' => 'USD', 'billing_cycle' => BillingCycle::Annually], ['price' => 99000, 'setup_fee' => 0]);
        $product->load('prices');

        $started = CarbonImmutable::today()->subMonths(4)->subDays(3);
        $service = $this->serviceWithHistory($client, $product, $started, 'server.'.self::slug((string) $client->company_name).'.com');
        $service->update(['username' => 'root']);

        foreach ([
            ['DS-101', Demo::BMC_HOSTS[0], 'U7', 8, MachineStatus::Active],
            ['DS-102', Demo::BMC_HOSTS[1], 'U8', 16, MachineStatus::Available],
            ['DS-103', Demo::BMC_HOSTS[2], 'U9', 24, MachineStatus::Wiping],
            ['DS-104', Demo::BMC_HOSTS[3], 'U10', 32, MachineStatus::Available],
            ['DS-105', Demo::BMC_HOSTS[4], 'U11', 40, MachineStatus::Available],
            ['DS-106', Demo::BMC_HOSTS[5], 'U12', 48, MachineStatus::Available],
        ] as $index => [$name, $bmc, $unit, $network, $status]) {
            $machine = new Machine([
                'name' => $name,
                'location' => 'Data center 1',
                'rack' => 'A1',
                'rack_unit' => $unit,
                'cpu' => 'Intel Xeon E-2388G',
                'cpu_cores' => 8,
                'ram_gb' => 64,
                'storage' => '2 × 960 GB NVMe',
                'port_speed' => '1 Gbps',
                'bmc_type' => 'redfish',
                'bmc_host' => $bmc,
                'bmc_port' => 443,
                'bmc_username' => 'operator',
                'bmc_password' => Str::random(32),
                'bmc_tls_mode' => 'pinned',
            ]);

            $machine->forceFill([
                'status' => $status,
                'service_id' => $status === MachineStatus::Active ? $service->id : null,
                'provisioned_at' => $status === MachineStatus::Active ? $started->addDay() : null,
                'released_at' => $status === MachineStatus::Wiping ? now()->subDay() : null,
                'bmc_tls_pin' => base64_encode(hash('sha256', 'Nuvabill demo BMC key '.$bmc, true)),
                'bmc_tls_fingerprint' => implode(':', str_split(strtoupper(hash('sha256', 'Nuvabill demo BMC certificate '.$bmc)), 2)),
                'bmc_system_path' => Demo::BMC_SYSTEM,
                'bmc_reset_target' => Demo::BMC_SYSTEM.'/Actions/ComputerSystem.Reset',
                'bmc_reset_types' => ['On', 'ForceOff', 'GracefulShutdown', 'GracefulRestart', 'ForceRestart'],
                'bmc_vendor' => 'Supermicro',
                'bmc_model' => 'SYS-510T-MR',
                'bmc_checked_at' => now()->subDays(10 + $index),
                'power_state' => $status === MachineStatus::Active ? PowerState::On->value : PowerState::Off->value,
                'power_checked_at' => now(),
            ])->save();

            $machine->products()->attach($product->id);

            // A /29 of documentation addresses: the gateway, the main IP, an included IP and a free extra IP.
            foreach ([[1, MachineIp::KIND_PRIMARY], [2, MachineIp::KIND_INCLUDED], [3, MachineIp::KIND_EXTRA]] as [$offset, $kind]) {
                $machine->ips()->create(['address' => '198.51.100.'.($network + 1 + $offset), 'version' => 4, 'prefix' => 29, 'gateway' => '198.51.100.'.($network + 1), 'kind' => $kind]);
            }

            $machine->ips()->create(['address' => '2001:db8:'.(101 + $index).'::2', 'version' => 6, 'prefix' => 64, 'gateway' => '2001:db8:'.(101 + $index).'::1', 'kind' => MachineIp::KIND_INCLUDED]);
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

    private function serviceWithHistory(Client $client, Product $product, CarbonImmutable $started, ?string $domain): Service
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

        return $service;
    }

    /**
     * The demo data comes with shared sign-ins that are public, and it renames the company. So it
     * only loads on the demo, on a development copy or in tests, and it stops before it changes
     * anything anywhere else.
     */
    private function refuseOnRealSites(): void
    {
        if (self::$forced || Demo::isEnabled() || app()->environment('local', 'testing')) {
            return;
        }

        throw new RuntimeException('This loads demo data and a public sign-in. It only runs on the demo or a local copy.');
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
            // Written in Arabic and translated for staff by AI help, to show translation in the demo.
            ['Technical support', 'Error after moving my website', TicketStatus::Open, [
                ['client', 'بعد نقل موقعي إلى الاستضافة الجديدة تظهر رسالة Error establishing a database connection. رفعت الملفات وقاعدة البيانات أمس. هل يمكنكم المساعدة؟',
                    'After moving my website to the new hosting, it shows “Error establishing a database connection”. I uploaded the files and the database yesterday. Can you help?', 'ar'],
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

            foreach ($messages as $position => $parts) {
                [$author, $message] = $parts;
                $reply = $ticket->replies()->create([
                    'author_type' => $author,
                    'author_id' => $author === 'admin' ? $admin->id : $client->id,
                    'message' => $message,
                    'translation' => $parts[2] ?? null,
                    'language' => $parts[3] ?? null,
                ]);
                $reply->forceFill(['created_at' => now()->subMinutes(25 * ($index + 1) + 60 * (count($messages) - $position))])->save();
            }
        }
    }
}
