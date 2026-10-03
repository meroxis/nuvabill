<?php

namespace Tests\Feature\Domains;

use App\Billing\PaymentRecorder;
use App\Domains\Rdap;
use App\Enums\DomainStatus;
use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\TldPrice;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Fixtures\Registrars\TestRegistrar;
use Tests\TestCase;

class DomainOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        TestRegistrar::reset();
    }

    public function test_the_search_shows_free_and_taken_names_with_prices(): void
    {
        $this->sellTld('com', 1299, registrar: 'testreg');
        $this->sellTld('net', 1499, registrar: 'testreg');
        TestRegistrar::$taken = ['myshop.com'];

        $this->get(route('store.domains', ['q' => 'https://www.MyShop.com']))
            ->assertOk()
            ->assertSeeInOrder(['myshop.com', 'Taken', 'myshop.net', 'Available', '$14.99 / year']);
    }

    public function test_extensions_without_a_registrar_are_checked_with_rdap(): void
    {
        $this->sellTld('io', 3900);
        Http::fake([
            Rdap::BOOTSTRAP_URL => Http::response(['services' => [[['io'], ['https://rdap.nic.io/']]]]),
            'https://rdap.nic.io/domain/free-name.io' => Http::response([], 404),
            'https://rdap.nic.io/domain/taken-name.io' => Http::response(['ldhName' => 'taken-name.io']),
        ]);

        $this->get(route('store.domains', ['q' => 'free-name.io']))->assertSeeInOrder(['free-name.io', 'Available']);
        $this->post(route('cart.domains.store'), ['domain' => 'taken-name.io', 'action' => 'register'])
            ->assertSessionHasErrors(['domain' => 'taken-name.io is already taken. Choose another name, or say you already own it.']);
    }

    public function test_a_paid_domain_order_is_registered_at_the_registrar(): void
    {
        Mail::fake();
        $this->sellTld('com', 1299, renew: 1499, registrar: 'testreg');
        $client = $this->client();

        $this->post(route('cart.domains.store'), ['domain' => 'bakery.com', 'action' => 'register', 'years' => 2])->assertRedirect(route('cart.show'));
        $this->actingAs($client, 'web')->get(route('cart.show'))->assertSee('Domain registration')->assertSee('$25.98');
        $this->post(route('checkout.store'))->assertRedirect();

        $domain = Domain::query()->where('name', 'bakery.com')->firstOrFail();
        $invoice = Order::query()->firstOrFail()->invoice;
        $this->assertSame(DomainStatus::Pending, $domain->status);
        $this->assertSame(2598, $invoice->total);
        $this->assertSame(2998, $domain->recurring_amount, 'The renewal price is the renew price for the same period.');
        $this->assertSame(InvoiceItem::TYPE_DOMAIN_REGISTER, $invoice->items->first()->type);
        $this->assertSame([], TestRegistrar::$calls, 'Nothing is registered before payment.');

        app(PaymentRecorder::class)->record($invoice, 2598, 'banktransfer');

        $domain->refresh();
        $this->assertSame([['register', 'bakery.com', '+964.7501234567']], TestRegistrar::$calls);
        $this->assertSame(DomainStatus::Active, $domain->status);
        $this->assertTrue($domain->expires_at->equalTo(today()->addYears(2)));
        $this->assertTrue($domain->next_due_date->equalTo($domain->expires_at));
        $this->assertSame('R-1', $domain->registrarValue('order_id'));
        $this->assertSame(OrderStatus::Active, $invoice->fresh()->client->orders()->first()->status);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'bakery.com is registered'));
    }

    public function test_a_registrar_failure_leaves_the_domain_pending_and_logged(): void
    {
        $this->sellTld('com', 1299, registrar: 'testreg');
        TestRegistrar::$fail = true;
        $domain = Domain::factory()->pending()->withRegistrar('testreg')->create(['client_id' => $this->client()->id, 'name' => 'nope.com']);

        $this->signInAdmin();
        $this->post(route('admin.domains.action', [$domain, 'register']))->assertSessionHas('error', 'The registrar said no.');

        $this->assertSame(DomainStatus::Pending, $domain->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'domain.registrar_failed']);
    }

    public function test_a_transfer_needs_the_epp_code_and_starts_after_payment(): void
    {
        $this->sellTld('com', 1299, transfer: 999, registrar: 'testreg');
        $client = $this->client();

        $this->post(route('cart.domains.store'), ['domain' => 'moving.com', 'action' => 'transfer'])->assertSessionHasErrors('epp_code');
        $this->post(route('cart.domains.store'), ['domain' => 'moving.com', 'action' => 'transfer', 'epp_code' => 'Secret#123']);
        $this->actingAs($client, 'web')->post(route('checkout.store'));

        $domain = Domain::query()->where('name', 'moving.com')->firstOrFail();
        $this->assertTrue($domain->isTransfer());
        $this->assertSame('Secret#123', $domain->epp_code);

        app(PaymentRecorder::class)->record(Order::query()->firstOrFail()->invoice, 999, 'banktransfer');

        $this->assertSame([['transfer', 'moving.com', 'Secret#123']], TestRegistrar::$calls);
        $this->assertSame(DomainStatus::PendingTransfer, $domain->fresh()->status);
        $this->assertNull($domain->fresh()->epp_code, 'The code is forgotten once the transfer has started.');

        $this->artisan('nuvabill:cron');

        $this->assertSame(DomainStatus::Active, $domain->fresh()->status, 'The nightly check notices the finished transfer.');
    }

    public function test_renewal_invoices_are_sent_and_paying_renews_the_domain(): void
    {
        $domain = Domain::factory()->withRegistrar('testreg')->expiringOn(today()->addDays(20))->create(['recurring_amount' => 1499]);
        $this->enableTestRegistrar();

        $this->artisan('nuvabill:cron');
        $this->artisan('nuvabill:cron');

        $invoice = Invoice::query()->where('client_id', $domain->client_id)->sole();
        $this->assertSame(1499, $invoice->total);
        $this->assertSame(InvoiceItem::TYPE_DOMAIN_RENEW, $invoice->items->first()->type);

        app(PaymentRecorder::class)->record($invoice, 1499, 'banktransfer');

        $this->assertContains(['renew', $domain->name, 1], TestRegistrar::$calls);
        $this->assertTrue($domain->fresh()->expires_at->equalTo(today()->addDays(20)->addYear()));
        $this->assertTrue($domain->fresh()->next_due_date->equalTo($domain->fresh()->expires_at));
    }

    public function test_domains_without_auto_renew_get_a_warning_and_then_expire(): void
    {
        Mail::fake();
        $warned = Domain::factory()->expiringOn(today()->addDays(7))->create(['auto_renew' => false]);
        $expired = Domain::factory()->expiringOn(today()->subDay())->create(['auto_renew' => false]);

        $this->artisan('nuvabill:cron');
        $this->artisan('nuvabill:cron');

        Mail::assertSent(TemplatedMessage::class, 1);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($warned->client->email) && str_contains($mail->subjectLine, 'expires in 7 days'));
        $this->assertSame(DomainStatus::Expired, $expired->fresh()->status);
        $this->assertSame(0, Invoice::query()->count(), 'No renewal invoice when auto-renew is off.');
    }

    public function test_every_configured_expiry_warning_is_sent_even_when_they_are_close_together(): void
    {
        Mail::fake();
        app(Settings::class)->set('domains.expiry_notice_days', [7, 3, 1]);
        $start = today();
        Domain::factory()->for($this->client(['first_name' => 'Raz']))->expiringOn($start->copy()->addDays(7))->create(['name' => 'raz-shop.com', 'auto_renew' => false]);

        for ($day = 0; $day <= 7; $day++) {
            $this->travelTo($start->copy()->addDays($day)->setTime(0, 15));
            $this->artisan('nuvabill:cron');
        }

        $subjects = Mail::sent(TemplatedMessage::class)->map(fn (TemplatedMessage $mail): string => $mail->subjectLine)->values()->all();
        $this->assertCount(3, $subjects);
        $this->assertStringContainsString('expires in 7 days', $subjects[0]);
        $this->assertStringContainsString('expires in 3 days', $subjects[1]);
        $this->assertStringContainsString('expires in 1 day', $subjects[2]);
    }

    public function test_domains_at_a_registrar_that_is_not_available_do_not_block_the_nightly_check(): void
    {
        $this->enableTestRegistrar();
        Domain::factory()->count(25)->withRegistrar('gone')->create(['last_synced_at' => null]);
        $moving = Domain::factory()->withRegistrar('testreg')->create(['status' => DomainStatus::PendingTransfer, 'last_synced_at' => now()->subDay()]);
        $stale = Domain::factory()->withRegistrar('testreg')->create(['last_synced_at' => now()->subDays(8)]);

        $this->artisan('nuvabill:cron');

        $this->assertContains(['sync', $moving->name], TestRegistrar::$calls);
        $this->assertContains(['sync', $stale->name], TestRegistrar::$calls);
        $this->assertSame(DomainStatus::Active, $moving->fresh()->status);
        $this->assertTrue($stale->fresh()->last_synced_at->isToday());
    }

    public function test_clients_manage_their_own_domains_only(): void
    {
        $this->enableTestRegistrar();
        $domain = Domain::factory()->withRegistrar('testreg')->create(['name' => 'mine.com']);
        $other = Domain::factory()->create();

        $this->actingAs($domain->client, 'web');
        $this->get(route('client.domains.index'))->assertOk()->assertSee('mine.com')->assertDontSee($other->name);
        $this->get(route('client.domains.show', $other))->assertNotFound();

        $this->put(route('client.domains.nameservers', $domain), ['nameservers' => ['NS1.Host.test', 'ns2.host.test', '', '']])
            ->assertSessionHas('status');

        $this->assertSame([['nameservers', 'mine.com', ['ns1.host.test', 'ns2.host.test']]], TestRegistrar::$calls);
        $this->assertSame(['ns1.host.test', 'ns2.host.test'], $domain->fresh()->nameservers);

        $this->put(route('client.domains.nameservers', $domain), ['nameservers' => ['ns1.host.test', '']])->assertSessionHasErrors('nameservers');
        $this->put(route('client.domains.nameservers', $other), ['nameservers' => ['a.test', 'b.test']])->assertNotFound();

        $this->post(route('client.domains.renew', $domain))->assertRedirect();
        $this->assertSame(InvoiceStatus::Unpaid, Invoice::query()->where('client_id', $domain->client_id)->sole()->status);
    }

    public function test_risky_orders_wait_for_staff_before_anything_is_set_up(): void
    {
        $this->sellTld('com', 1299, registrar: 'testreg');
        $client = $this->client(['email' => 'someone@mailinator.com']);

        $this->post(route('cart.domains.store'), ['domain' => 'risky.com', 'action' => 'register']);
        $this->actingAs($client, 'web')->post(route('checkout.store'));

        $order = Order::query()->firstOrFail();
        $this->assertTrue($order->needs_review);
        $this->assertSame(['The email address is from a throwaway email service.'], $order->fraud_reasons);

        app(PaymentRecorder::class)->record($order->invoice, 1299, 'banktransfer');

        $this->assertSame([], TestRegistrar::$calls, 'A paid but risky order is not registered automatically.');
        $this->assertSame(OrderStatus::Pending, $order->fresh()->status);

        $this->signInAdmin();
        $this->get(route('admin.orders.show', $order))->assertSee('throwaway email service');
        $this->post(route('admin.orders.accept', $order))->assertSessionHas('status');

        $this->assertSame(DomainStatus::Active, Domain::query()->where('name', 'risky.com')->sole()->status);
        $this->assertFalse($order->fresh()->needs_review);
    }

    public function test_staff_pages_for_domains_prices_and_registrars_load(): void
    {
        $this->signInAdmin();
        $domain = Domain::factory()->withRegistrar('testreg')->create();

        $this->get(route('admin.domains.index'))->assertOk()->assertSee($domain->name);
        $this->get(route('admin.domains.show', $domain))->assertOk();
        $this->get(route('admin.settings.registrars.index'))->assertRedirect(route('admin.extensions.index', ['tab' => 'registrars']));
        $this->get(route('admin.extensions.index', ['tab' => 'registrars']))->assertOk()->assertSee('Test Registrar')->assertSee('Domains: 1');
        $this->get(route('admin.settings.registrars.edit', 'testreg'))->assertOk();

        $this->post(route('admin.settings.tlds.store'), [
            'tld' => '.CO.UK', 'currency' => 'usd', 'registrar' => 'testreg', 'register_price' => '8.50',
            'renew_price' => '9', 'transfer_price' => '8', 'min_years' => 1, 'max_years' => 5, 'is_enabled' => 1,
        ])->assertRedirect(route('admin.settings.tlds.index'));

        $price = TldPrice::query()->sole();
        $this->assertSame(['co.uk', 'USD', 850], [$price->tld, $price->currency, $price->register_price]);
        $this->get(route('admin.settings.tlds.index'))->assertOk()->assertSee('.co.uk');
        $this->get(route('admin.settings.tlds.edit', $price))->assertOk();
    }

    private function sellTld(string $tld, int $register, ?int $renew = null, ?int $transfer = null, ?string $registrar = null): TldPrice
    {
        if ($registrar !== null) {
            $this->enableTestRegistrar();
        }

        return TldPrice::create([
            'tld' => $tld,
            'currency' => 'USD',
            'registrar' => $registrar,
            'register_price' => $register,
            'renew_price' => $renew ?? $register,
            'transfer_price' => $transfer ?? $register,
        ]);
    }

    private function enableTestRegistrar(): void
    {
        app(ExtensionManager::class)->saveSettings('testreg', ['api_key' => 'test'], true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function client(array $attributes = []): Client
    {
        return Client::factory()->create($attributes + [
            'phone' => '0750 123 4567',
            'country' => 'IQ',
            'address_1' => '12 Cloud Street',
            'city' => 'Erbil',
            'currency' => 'USD',
        ]);
    }
}
