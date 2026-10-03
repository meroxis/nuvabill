<?php

namespace Tests\Feature\Admin;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\Wallet;
use App\Http\Controllers\Admin\DashboardController;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Transaction;
use App\Support\Activity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Billing data (revenue, unpaid totals, invoices, payments, the wallet) is shown only to staff with
 * billing.view, and the site-wide activity only to staff with settings.manage, the same as on the
 * pages it comes from.
 */
class StaffBillingVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setSettings(['billing.currency' => 'USD']);
    }

    public function test_the_dashboard_hides_billing_and_activity_from_a_support_role(): void
    {
        $this->billingData();
        $support = Admin::factory()->withPermissions(['clients.view', 'support.manage'])->create(['name' => 'Raz']);

        $this->signInAdmin($support);
        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Tickets waiting for a reply')
            ->assertDontSee('Monthly revenue')
            ->assertDontSee('Unpaid invoices')
            ->assertDontSee('$123.45')
            ->assertDontSee('secret-reason-marker');

        $data = app(DashboardController::class)()->getData();
        $this->assertArrayNotHasKey('revenueThisMonth', $data);
        $this->assertArrayNotHasKey('chart', $data);
        $this->assertTrue($data['activity']->isEmpty());
        $this->assertTrue($data['recentOrders']->isEmpty());
    }

    public function test_the_dashboard_shows_everything_to_an_owner(): void
    {
        $this->billingData();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));

        $this->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Monthly revenue')
            ->assertSee('Unpaid invoices')
            ->assertSee('$123.45')
            ->assertSee('secret-reason-marker');
    }

    public function test_the_revenue_chart_adds_up_months_in_the_database(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
        $this->setSettings(['wallet.enabled' => true]);
        $client = $this->client();
        $invoice = Invoice::factory()->for($client)->create();

        $this->transaction($client, $invoice, 1000, '2026-10-02 09:00:00');
        $this->transaction($client, $invoice, 500, '2026-10-14 18:30:00');
        $this->transaction($client, $invoice, 700, '2026-08-01 00:00:00');
        $this->transaction($client, $invoice, -200, '2026-08-31 23:59:59', 'refund');
        $this->transaction($client, $invoice, 900, '2026-10-03 10:00:00', 'payment', Wallet::GATEWAY);
        $this->transaction($client, $invoice, 800, '2026-10-03 10:00:00', 'payment', 'banktransfer', 'EUR');
        $this->transaction($client, $invoice, 600, '2025-09-30 10:00:00');

        $hydrated = 0;
        Event::listen('eloquent.retrieved: '.Transaction::class, function () use (&$hydrated): void {
            $hydrated++;
        });

        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->get(route('admin.dashboard'))->assertOk();
        $chart = app(DashboardController::class)()->getData()['chart'];

        $values = array_column($chart['bars'], 'value');
        $this->assertSame(1500, $values[11]);
        $this->assertSame(500, $values[9]);
        $this->assertSame(0, array_sum($values) - 1500 - 500, 'Every other month is empty.');
        $this->assertSame(2000, $chart['max']);
        $this->assertSame(0, $hydrated, 'The chart does not load each payment.');
    }

    public function test_the_client_page_hides_billing_without_billing_view(): void
    {
        $client = $this->client(['credit' => 5000]);
        $invoice = Invoice::factory()->for($client)->create(['number' => 'INV-778899', 'subtotal' => 12345, 'total' => 12345, 'currency' => 'USD']);
        $this->transaction($client, $invoice, 12345, now()->toDateTimeString(), reference: 'ch_hidden_ref_1');

        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz']));
        $this->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertSee('Mer Las')
            ->assertDontSee('ch_hidden_ref_1')
            ->assertDontSee('INV-778899')
            ->assertDontSee('$123.45')
            ->assertDontSee('$50.00');

        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view', 'billing.view'])->create(['name' => 'Mer Las']));
        $this->get(route('admin.clients.show', $client))
            ->assertOk()
            ->assertSee('ch_hidden_ref_1')
            ->assertSee('INV-778899')
            ->assertSee('$123.45')
            ->assertSee('$50.00');
    }

    public function test_the_api_gives_the_wallet_balance_only_to_keys_that_may_see_billing(): void
    {
        $client = $this->client(['credit' => 5000]);
        [, $clientsOnly] = ApiToken::issue(Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz']), 'Reports', false);
        [, $withBilling] = ApiToken::issue(Admin::factory()->withPermissions(['clients.view', 'billing.view'])->create(['name' => 'Mer Las']), 'Reports', false);

        $this->getJson("/api/v1/clients/{$client->id}", ['Authorization' => "Bearer {$clientsOnly}"])
            ->assertOk()
            ->assertJsonPath('data.email', $client->email)
            ->assertJsonMissingPath('data.wallet_balance');

        $this->app['auth']->forgetGuards();

        $this->getJson("/api/v1/clients/{$client->id}", ['Authorization' => "Bearer {$withBilling}"])
            ->assertOk()
            ->assertJsonPath('data.wallet_balance', 5000);
    }

    private function billingData(): void
    {
        $client = $this->client();
        $paid = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 12345]]);
        app(PaymentRecorder::class)->record($paid, 12345, 'banktransfer', 'bank-1');
        app(InvoiceManager::class)->create($client, [['description' => 'Domain', 'amount' => 999]]);
        Activity::log('wallet.changed', 'Added $50.00 (wallet): secret-reason-marker', $client, $client);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function client(array $attributes = []): Client
    {
        return Client::factory()->create($attributes + ['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'currency' => 'USD']);
    }

    private function transaction(Client $client, Invoice $invoice, int $amount, string $paidAt, string $type = 'payment', string $gateway = 'banktransfer', string $currency = 'USD', ?string $reference = null): Transaction
    {
        return Transaction::query()->create([
            'client_id' => $client->id,
            'invoice_id' => $invoice->id,
            'type' => $type,
            'gateway' => $gateway,
            'reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'paid_at' => $paidAt,
        ]);
    }
}
