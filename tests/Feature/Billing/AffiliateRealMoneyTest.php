<?php

namespace Tests\Feature\Billing;

use App\Billing\Affiliates;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\ResourceChanges;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Order;
use App\Models\Product;
use App\Models\ResourceOption;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Commissions follow real money: only the part of an invoice that was paid through a payment
 * method earns one, wallet credit for unused time cuts it during the hold, and the commissions
 * for one referred client never stand for more money than that client brought in.
 */
class AffiliateRealMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Affiliate $affiliate;

    private Client $raz;

    private Product $starter;

    private Product $business;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // 15 of the 30 days of the service's period are left.
        $this->travelTo('2026-10-01 10:00');
        $this->setSettings([
            'affiliates.enabled' => true,
            'affiliates.recurring' => true,
            'affiliates.percent' => 10,
            'affiliates.hold_days' => 30,
            'wallet.enabled' => true,
            'billing.currency' => 'USD',
        ]);

        $this->affiliate = app(Affiliates::class)->join(Client::factory()->create(['first_name' => 'Mer', 'last_name' => '', 'currency' => 'USD']));
        $this->raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'currency' => 'USD']);
        AffiliateReferral::query()->create(['affiliate_id' => $this->affiliate->id, 'client_id' => $this->raz->id]);

        $this->starter = Product::factory()->priced(1000)->create(['name' => 'Starter']);
        $this->business = Product::factory()->priced(2000)->create(['name' => 'Business']);
        $this->starter->update(['upgrade_product_ids' => [$this->business->id]]);
        $this->business->update(['upgrade_product_ids' => [$this->starter->id]]);
    }

    public function test_an_invoice_paid_through_a_payment_method_earns_the_full_commission(): void
    {
        $invoice = $this->invoice(2000);
        app(PaymentRecorder::class)->record($invoice, 2000, 'banktransfer', 'bank-1');

        $commission = AffiliateCommission::query()->sole();
        $this->assertSame([200, 2000, AffiliateCommission::STATUS_PENDING], [$commission->amount, $commission->base, $commission->status]);

        $this->travel(31)->days();
        $this->assertSame(1, app(Affiliates::class)->release());
        $this->assertSame([200, 2000, AffiliateCommission::STATUS_AVAILABLE], [$commission->fresh()->amount, $commission->fresh()->base, $commission->fresh()->status]);
    }

    public function test_tax_is_left_out_of_a_part_payment_too(): void
    {
        // $100.00 plus $20.00 tax: half paid from the wallet, half by bank transfer.
        $invoice = Invoice::factory()->for($this->raz)->create(['subtotal' => 10000, 'tax' => 2000, 'total' => 12000]);
        app(Wallet::class)->change($this->raz, 6000, 'Goodwill');
        $this->assertSame(6000, app(Wallet::class)->pay($invoice));
        app(PaymentRecorder::class)->record($invoice, 6000, 'banktransfer', 'bank-1');

        $commission = AffiliateCommission::query()->sole();
        $this->assertSame(500, $commission->amount, '10% of the half of the $100.00 sale that real money paid.');
        $this->assertSame(6000, $commission->base);
    }

    public function test_money_paid_from_the_wallet_earns_no_commission(): void
    {
        app(Wallet::class)->change($this->raz, 2000, 'Goodwill');
        $invoice = $this->invoice(2000);

        $this->assertSame(2000, app(Wallet::class)->pay($invoice));

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, AffiliateCommission::query()->count());
    }

    public function test_a_new_order_earns_only_for_the_part_real_money_paid(): void
    {
        $this->setSettings(['affiliates.recurring' => false]);
        app(Wallet::class)->change($this->raz, 3000, 'Goodwill');
        $product = Product::factory()->priced(5000)->create();

        $this->actingAs($this->raz, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'raz.example.net']);
        $this->post(route('checkout.store'));
        $invoice = Order::query()->sole()->invoice;
        $this->assertSame(2000, $invoice->fresh()->balance(), 'The wallet paid $30.00 of the $50.00 order.');
        app(PaymentRecorder::class)->record($invoice, 2000, 'banktransfer', 'bank-1');

        $commission = AffiliateCommission::query()->sole();
        $this->assertSame([200, 2000], [$commission->amount, $commission->base]);
    }

    public function test_an_order_the_wallet_pays_in_full_earns_nothing(): void
    {
        $this->setSettings(['affiliates.recurring' => false]);
        app(Wallet::class)->change($this->raz, 5000, 'Goodwill');
        $product = Product::factory()->priced(5000)->create();

        $this->actingAs($this->raz, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'raz.example.net']);
        $this->post(route('checkout.store'));

        $this->assertSame(InvoiceStatus::Paid, Order::query()->sole()->invoice->status);
        $this->assertSame(0, AffiliateCommission::query()->count());
    }

    public function test_moving_to_a_smaller_plan_and_back_earns_nothing_more(): void
    {
        [$service, $period] = $this->paidBusinessPeriod();
        $this->assertSame(200, AffiliateCommission::query()->sole()->amount);
        $this->actingAs($this->raz, 'web');

        foreach ([1, 2] as $round) {
            // Down: the unused half of the price difference goes into the wallet.
            $this->post(route('client.services.change-plan.store', $service), ['product_id' => $this->starter->id])->assertRedirect();
            $this->assertSame(500, $this->raz->fresh()->credit);

            // Up again: the wallet pays the invoice, with the same money.
            $this->post(route('client.services.change-plan.store', $service), ['product_id' => $this->business->id])->assertRedirect();
            $this->assertSame(0, $this->raz->fresh()->credit);
            $this->assertSame($this->business->id, $service->fresh()->product_id);
        }

        $this->assertSame(2, Invoice::query()->where('payment_method', Wallet::GATEWAY)->where('status', InvoiceStatus::Paid)->count());
        $this->assertSame([$period->id], AffiliateCommission::query()->pluck('invoice_id')->all(), 'Only the invoice paid with real money earns.');

        $this->travel(31)->days();
        app(Affiliates::class)->release();

        // $20.00 came in and $10.00 of it went back to the wallet as unused time.
        $this->assertSame([100], AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_AVAILABLE)->pluck('amount')->all());
        $this->assertSame(100, app(Affiliates::class)->withdrawToWallet($this->affiliate));
    }

    public function test_wallet_credit_for_a_smaller_plan_cuts_the_commission_on_release(): void
    {
        [$service] = $this->paidBusinessPeriod();

        $this->actingAs($this->raz, 'web')->post(route('client.services.change-plan.store', $service), ['product_id' => $this->starter->id])->assertRedirect();
        $this->assertSame(500, $this->raz->fresh()->credit);

        $this->travel(31)->days();
        $this->assertSame(1, app(Affiliates::class)->release());

        $commission = AffiliateCommission::query()->sole();
        $this->assertSame([150, 1500], [$commission->amount, $commission->base], 'The client kept $15.00 of the $20.00.');
    }

    public function test_a_plan_change_before_the_invoice_was_paid_does_not_cut_its_commission(): void
    {
        $service = Service::factory()->create(['client_id' => $this->raz->id, 'product_id' => $this->business->id, 'recurring_amount' => 2000, 'next_due_date' => '2026-10-16']);
        $this->actingAs($this->raz, 'web')->post(route('client.services.change-plan.store', $service), ['product_id' => $this->starter->id])->assertRedirect();
        $this->assertSame(500, $this->raz->fresh()->credit);

        $this->travel(1)->days();
        $invoice = $this->invoice(1000, $service);
        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer', 'bank-1');

        $this->travel(31)->days();
        app(Affiliates::class)->release();

        $this->assertSame([100, AffiliateCommission::STATUS_AVAILABLE], [AffiliateCommission::query()->sole()->amount, AffiliateCommission::query()->sole()->status]);
    }

    public function test_wallet_credit_for_extras_taken_off_cuts_the_commission_on_release(): void
    {
        // A monthly service due 1 Nov: the period has 31 days and 17 are left.
        $this->travelTo('2026-10-15 10:00');
        $vps = Product::factory()->priced(1000)->withoutDomain()->create(['name' => 'VPS 2']);
        $ram = ResourceOption::factory()->priced(200)->create(['product_id' => $vps->id]);
        $service = Service::factory()->create(['client_id' => $this->raz->id, 'product_id' => $vps->id, 'recurring_amount' => 1000, 'registration_date' => '2026-07-01', 'next_due_date' => '2026-11-01']);
        $row = ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $ram->id, 'steps' => 5, 'recurring_amount' => 1000]);
        $invoice = app(InvoiceManager::class)->create($this->raz, [[
            'type' => InvoiceItem::TYPE_RESOURCE,
            'description' => 'RAM',
            'amount' => 1000,
            'service_id' => $service->id,
            'service_resource_option_id' => $row->id,
            'period_start' => CarbonImmutable::parse('2026-10-01'),
            'period_end' => CarbonImmutable::parse('2026-10-31'),
        ]], currency: 'USD');
        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer', 'bank-1');
        $this->assertSame(100, AffiliateCommission::query()->sole()->amount);

        $this->travel(1)->hours();
        $changes = app(ResourceChanges::class);
        $changes->start($service->fresh(), ['ram' => 1], expected: $changes->quote($service->fresh(), ['ram' => 1])['difference']);
        $this->assertSame(438, $this->raz->fresh()->credit, 'Four of the five steps for 17 of 31 days, as paid.');

        $this->travel(31)->days();
        app(Affiliates::class)->release();

        $this->assertSame(56, AffiliateCommission::query()->sole()->amount, 'The client kept $5.62 of the $10.00.');
    }

    public function test_commissions_never_stand_for_more_money_than_the_client_brought_in(): void
    {
        $first = $this->invoice(2000);
        app(PaymentRecorder::class)->record($first, 2000, 'banktransfer', 'bank-1');
        $this->travel(31)->days();
        app(Affiliates::class)->release();
        $this->assertSame(AffiliateCommission::STATUS_AVAILABLE, AffiliateCommission::query()->sole()->status);

        // The money of the first invoice goes back after its commission was released.
        $this->signInAdmin();
        $this->post(route('admin.invoices.refund', $first), ['through_gateway' => '0'])->assertSessionHas('status');

        // The next $20.00 only brings back what left, so no commission stands on it.
        $second = $this->invoice(2000);
        app(PaymentRecorder::class)->record($second, 2000, 'banktransfer', 'bank-2');
        $this->assertSame(1, AffiliateCommission::query()->count());

        // Money beyond that is new, so it earns again.
        $third = $this->invoice(1000);
        app(PaymentRecorder::class)->record($third, 1000, 'banktransfer', 'bank-3');
        $commission = AffiliateCommission::query()->where('invoice_id', $third->id)->sole();
        $this->assertSame([100, 1000], [$commission->amount, $commission->base]);
    }

    public function test_a_commission_on_hold_is_cut_when_earlier_money_went_back(): void
    {
        $first = $this->invoice(2000);
        app(PaymentRecorder::class)->record($first, 2000, 'banktransfer', 'bank-1');
        $this->travel(31)->days();
        app(Affiliates::class)->release();

        $second = $this->invoice(2000);
        app(PaymentRecorder::class)->record($second, 2000, 'banktransfer', 'bank-2');
        $held = AffiliateCommission::query()->where('invoice_id', $second->id)->sole();
        $this->assertSame([200, AffiliateCommission::STATUS_PENDING], [$held->amount, $held->status]);

        $this->signInAdmin();
        $this->post(route('admin.invoices.refund', $first), ['through_gateway' => '0'])->assertSessionHas('status');

        $this->travel(31)->days();
        app(Affiliates::class)->release();

        // $20.00 is left of what the client brought in, and the released commission stands for it.
        $this->assertSame(AffiliateCommission::STATUS_CANCELLED, $held->fresh()->status);
    }

    public function test_money_that_went_back_on_one_invoice_does_not_cut_the_commission_of_another(): void
    {
        $first = $this->invoice(2000);
        app(PaymentRecorder::class)->record($first, 2000, 'banktransfer', 'bank-1');
        $second = $this->invoice(2000);
        app(PaymentRecorder::class)->record($second, 2000, 'banktransfer', 'bank-2');

        // Half of the second invoice goes back while both commissions are on hold.
        $this->signInAdmin();
        $this->post(route('admin.invoices.credit-notes.store', $second), ['amount' => '10.00', 'method' => 'refund', 'through_gateway' => '0'])->assertSessionHas('status');

        $this->travel(31)->days();
        $this->assertSame(2, app(Affiliates::class)->release());

        $kept = AffiliateCommission::query()->where('invoice_id', $first->id)->sole();
        $cut = AffiliateCommission::query()->where('invoice_id', $second->id)->sole();
        $this->assertSame([200, 2000], [$kept->amount, $kept->base], 'The first invoice kept all its money.');
        $this->assertSame([100, 1000], [$cut->amount, $cut->base]);

        // A new payment while a cut commission is on hold earns in full too.
        $third = $this->invoice(2000);
        app(PaymentRecorder::class)->record($third, 2000, 'banktransfer', 'bank-3');
        $this->post(route('admin.invoices.credit-notes.store', $third), ['amount' => '15.00', 'method' => 'refund', 'through_gateway' => '0'])->assertSessionHas('status');
        $fourth = $this->invoice(1000);
        app(PaymentRecorder::class)->record($fourth, 1000, 'banktransfer', 'bank-4');

        $this->assertSame([100, 1000], [AffiliateCommission::query()->where('invoice_id', $fourth->id)->sole()->amount, AffiliateCommission::query()->where('invoice_id', $fourth->id)->sole()->base]);
    }

    public function test_an_older_commission_without_its_money_is_released_as_before(): void
    {
        $invoice = Invoice::factory()->for($this->raz)->paid()->create(['total' => 10000, 'subtotal' => 10000]);
        $commission = $this->affiliate->commissions()->create(['client_id' => $this->raz->id, 'invoice_id' => $invoice->id, 'amount' => 1000, 'currency' => 'USD', 'status' => AffiliateCommission::STATUS_PENDING, 'available_at' => today()]);

        $this->assertSame(AffiliateCommission::STATUS_AVAILABLE, app(Affiliates::class)->releaseOne($commission));
        $this->assertSame([1000, null], [$commission->fresh()->amount, $commission->fresh()->base]);
    }

    public function test_the_migration_runs_again_without_harm_and_can_be_undone(): void
    {
        $migration = require database_path('migrations/2027_08_01_000025_add_base_to_affiliate_commissions.php');

        $migration->up();
        $this->assertTrue(Schema::hasColumn('affiliate_commissions', 'base'));

        $migration->down();
        $this->assertFalse(Schema::hasColumn('affiliate_commissions', 'base'));
        $migration->down();

        $migration->up();
        $this->assertTrue(Schema::hasColumn('affiliate_commissions', 'base'));
    }

    /**
     * An unpaid invoice of the referred client, with a line for the service when one is given.
     */
    private function invoice(int $total, ?Service $service = null): Invoice
    {
        $invoice = Invoice::factory()->for($this->raz)->create(['total' => $total, 'subtotal' => $total]);

        if ($service !== null) {
            $invoice->items()->create(['type' => InvoiceItem::TYPE_SERVICE, 'service_id' => $service->id, 'description' => 'Hosting', 'amount' => $total]);
        }

        return $invoice;
    }

    /**
     * A service on the Business plan whose current period (16 Sep - 15 Oct) was paid by bank transfer.
     *
     * @return array{0: Service, 1: Invoice}
     */
    private function paidBusinessPeriod(): array
    {
        $service = Service::factory()->create(['client_id' => $this->raz->id, 'product_id' => $this->business->id, 'recurring_amount' => 2000, 'next_due_date' => '2026-10-16']);
        $invoice = app(InvoiceManager::class)->create($this->raz, [[
            'type' => InvoiceItem::TYPE_SERVICE,
            'description' => 'Business',
            'amount' => 2000,
            'service_id' => $service->id,
            'period_start' => CarbonImmutable::parse('2026-09-16'),
            'period_end' => CarbonImmutable::parse('2026-10-15'),
        ]], currency: 'USD');
        app(PaymentRecorder::class)->record($invoice, 2000, 'banktransfer', 'bank-period');

        return [$service->fresh(), $invoice->fresh()];
    }
}
