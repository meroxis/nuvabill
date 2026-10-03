<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\CreditNotes;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Plan changes that must not cost the company money: an upgrade paid after the renewal, refunded
 * periods, a deleted target plan, a wallet that cannot take the credit, and sold-out plans.
 */
class PlanChangeSafetyTest extends TestCase
{
    use RefreshDatabase;

    private Product $starter;

    private Product $business;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        // 15 of the 30 days in this period are left.
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $this->setSettings(['wallet.enabled' => true]);

        $this->starter = Product::factory()->priced(1000)->create(['name' => 'Starter']);
        $this->business = Product::factory()->priced(2000)->create(['name' => 'Business']);
        $this->starter->update(['upgrade_product_ids' => [$this->business->id]]);
        $this->business->update(['upgrade_product_ids' => [$this->starter->id]]);

        $this->service = Service::factory()->create([
            'client_id' => Client::factory()->create(['currency' => 'USD'])->id,
            'product_id' => $this->starter->id,
            'recurring_amount' => 1000,
            'next_due_date' => '2026-10-16',
            'domain' => 'shop.example.net',
        ]);
    }

    public function test_an_upgrade_still_unpaid_when_the_renewal_is_billed_is_stopped(): void
    {
        $upgrade = $this->requestUpgrade();

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $this->assertSame(InvoiceStatus::Cancelled, $upgrade->fresh()->status);
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);

        $renewal = Invoice::query()->whereKeyNot($upgrade->id)->sole();
        $this->assertSame(1000, $renewal->total, 'The renewal bills the plan the service is on.');
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
    }

    public function test_an_upgrade_paid_just_before_the_renewal_is_billed_bills_the_new_plan(): void
    {
        $upgrade = $this->requestUpgrade();
        app(PaymentRecorder::class)->record($upgrade, 500, 'banktransfer');

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $this->assertSame($this->business->id, $this->service->fresh()->product_id);
        $this->assertSame(2000, Invoice::query()->whereKeyNot($upgrade->id)->sole()->total);
    }

    public function test_an_upgrade_paid_after_the_renewal_was_paid_does_not_move_the_renewed_period(): void
    {
        $upgrade = $this->requestUpgrade();
        $renewal = $this->renewalAtTheOldPrice();

        app(PaymentRecorder::class)->record($renewal, 1000, 'banktransfer');
        $this->assertTrue($this->service->fresh()->next_due_date->isSameDay('2026-11-16'));

        app(PaymentRecorder::class)->record($upgrade, 500, 'banktransfer');

        $this->assertUpgradeStoppedAndPaidBack($upgrade);
    }

    public function test_an_upgrade_paid_while_the_renewal_is_still_open_does_not_move_the_next_period(): void
    {
        $upgrade = $this->requestUpgrade();
        $renewal = $this->renewalAtTheOldPrice();

        app(PaymentRecorder::class)->record($upgrade, 500, 'banktransfer');
        $this->assertUpgradeStoppedAndPaidBack($upgrade);

        app(PaymentRecorder::class)->record($renewal, 1000, 'banktransfer');

        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
        $this->actingAs($this->service->client)->get(route('client.services.change-plan', $this->service))->assertOk()->assertSee('Business');
    }

    public function test_a_downgrade_never_credits_more_than_was_paid_for_the_days_left(): void
    {
        // The service moved to Business without paying for it (for example by staff, at no charge).
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);
        $this->paidPeriod(1000);

        $quote = app(PlanChanges::class)->quote($this->service->fresh(), $this->starter);

        $this->assertSame(500, $quote['credit'], 'Half of the $10 really paid, not half of $20.');
        $this->assertLessThanOrEqual(0, $quote['difference']);
        $this->assertSame(0, $quote['difference'], 'Moving down costs nothing and pays nothing back here.');
    }

    public function test_a_refunded_or_partly_credited_period_gives_no_or_less_downgrade_credit(): void
    {
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);
        $refunded = $this->paidPeriod(2000);

        app(CreditNotes::class)->issue($refunded, 2000, CreditNote::METHOD_NONE);

        $this->assertSame(InvoiceStatus::Refunded, $refunded->fresh()->status);
        $this->assertSame(0, app(PlanChanges::class)->quote($this->service->fresh(), $this->starter)['difference']);

        $halfBack = $this->paidPeriod(2000);
        app(CreditNotes::class)->issue($halfBack, 1000, CreditNote::METHOD_NONE);

        // Half of $5 (the unused half of the price difference), as half the period was given back.
        $this->assertSame(-250, app(PlanChanges::class)->quote($this->service->fresh(), $this->starter)['difference']);

        $this->actingAs($this->service->client)
            ->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->starter->id])
            ->assertRedirect(route('client.services.show', $this->service));

        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
        $this->assertSame(250, $this->service->client->fresh()->credit);
    }

    public function test_a_product_waiting_as_a_plan_change_target_cannot_be_deleted(): void
    {
        $this->waitingDowngrade();
        $this->signInAdmin();

        $this->delete(route('admin.products.destroy', $this->starter))
            ->assertSessionHas('error', 'A client is waiting to move to this product. Stop that plan change first, or hide the product instead.');

        $this->assertModelExists($this->starter);
    }

    public function test_a_deleted_plan_target_does_not_stop_the_nightly_run(): void
    {
        $this->waitingDowngrade();
        $this->starter->delete();

        $this->assertSame(0, app(PlanChanges::class)->applyScheduled(Carbon::parse('2026-10-16')));
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame($this->business->id, $this->service->fresh()->product_id);
        $this->assertTrue(ActivityLog::query()->where('action', 'service.plan_change_stopped')->exists());

        PlanChange::query()->update(['status' => PlanChange::STATUS_PENDING]);
        $this->travelTo(Carbon::parse('2026-10-16 03:00'));

        $this->assertIsArray(app(DailyAutomation::class)->run());
    }

    public function test_a_paid_upgrade_to_a_deleted_product_still_finishes_the_payment(): void
    {
        $upgrade = $this->requestUpgrade();
        $this->business->delete();

        app(PaymentRecorder::class)->record($upgrade, 500, 'banktransfer');

        $this->assertSame(InvoiceStatus::Refunded, $upgrade->fresh()->status, 'Paid, then given back to the wallet.');
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
        $this->assertSame(500, $this->service->client->fresh()->credit);
    }

    public function test_a_downgrade_waits_for_the_renewal_when_the_credit_cannot_go_into_the_wallet(): void
    {
        // The service is billed in euros, the client's wallet is in dollars.
        foreach ([$this->starter, $this->business] as $product) {
            $product->prices()->create(['currency' => 'EUR', 'billing_cycle' => 'monthly', 'price' => $product->id === $this->starter->id ? 1000 : 2000, 'setup_fee' => 0]);
        }

        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000, 'currency' => 'EUR']);
        $this->assertDowngradeWaits();
    }

    public function test_a_downgrade_waits_for_the_renewal_when_the_wallet_is_off(): void
    {
        $this->setSettings(['wallet.enabled' => false]);
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);

        $this->assertDowngradeWaits();
    }

    public function test_clients_cannot_move_to_a_sold_out_plan_but_staff_can(): void
    {
        $this->business->update(['stock' => 1]);
        Service::factory()->create(['product_id' => $this->business->id, 'status' => ServiceStatus::Active]);
        $this->actingAs($this->service->client);

        $this->get(route('client.services.change-plan', $this->service))->assertOk()->assertDontSee('Switch to Business');
        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->business->id])->assertSessionHas('error');

        $this->assertSame(0, PlanChange::query()->count());
        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);

        app(PlanChanges::class)->start($this->service->fresh(), $this->business, Admin::factory()->create(), charge: false);
        $this->assertSame($this->business->id, $this->service->fresh()->product_id);
    }

    public function test_a_paid_upgrade_to_a_plan_that_sold_out_meanwhile_is_given_back(): void
    {
        $this->business->update(['stock' => 1]);
        $upgrade = $this->requestUpgrade();
        Service::factory()->create(['product_id' => $this->business->id, 'status' => ServiceStatus::Active]);

        app(PaymentRecorder::class)->record($upgrade, 500, 'banktransfer');

        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame(500, $this->service->client->fresh()->credit);
    }

    /**
     * The client asks for Business with an empty wallet: a $5 invoice for the 15 days left.
     */
    private function requestUpgrade(): Invoice
    {
        $this->actingAs($this->service->client)
            ->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->business->id])
            ->assertRedirect();

        $upgrade = Invoice::query()->latest('id')->firstOrFail();
        $this->assertSame(500, $upgrade->total);
        $this->assertSame(InvoiceStatus::Unpaid, $upgrade->status);

        return $upgrade;
    }

    /**
     * The renewal for the next month at the Starter price, as the nightly run made it.
     */
    private function renewalAtTheOldPrice(): Invoice
    {
        return app(InvoiceManager::class)->create($this->service->client, [[
            'type' => InvoiceItem::TYPE_SERVICE,
            'service_id' => $this->service->id,
            'description' => 'Starter',
            'amount' => 1000,
            'period_start' => Carbon::parse('2026-10-16'),
            'period_end' => Carbon::parse('2026-11-15'),
            'billing_key' => RenewalGenerator::billingKey('service', $this->service->id, Carbon::parse('2026-10-16')),
        ]], dueAt: Carbon::parse('2026-10-16'));
    }

    private function assertUpgradeStoppedAndPaidBack(Invoice $upgrade): void
    {
        $service = $this->service->fresh();
        $this->assertSame($this->starter->id, $service->product_id);
        $this->assertSame(1000, $service->recurring_amount);
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame(InvoiceStatus::Refunded, $upgrade->fresh()->status);
        $this->assertSame(500, $service->client->fresh()->credit, 'The upgrade payment goes back to the wallet, once.');
    }

    /**
     * A paid invoice for the current period of the service.
     */
    private function paidPeriod(int $price): Invoice
    {
        $invoice = Invoice::factory()->for($this->service->client)->create(['status' => InvoiceStatus::Paid, 'subtotal' => $price, 'total' => $price, 'amount_paid' => $price, 'currency' => 'USD']);
        $invoice->items()->create(['type' => InvoiceItem::TYPE_SERVICE, 'service_id' => $this->service->id, 'description' => 'Business', 'amount' => $price, 'period_start' => '2026-09-16', 'period_end' => '2026-10-15']);

        return $invoice;
    }

    /**
     * Business moving down to Starter on the next renewal date.
     */
    private function waitingDowngrade(): void
    {
        $this->setSettings(['billing.downgrade' => 'renewal']);
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);

        $change = app(PlanChanges::class)->start($this->service->fresh(), $this->starter);
        $this->assertSame(PlanChange::MODE_RENEWAL, $change->mode);
        $this->assertSame(0, $this->starter->services()->count());
    }

    private function assertDowngradeWaits(): void
    {
        $this->actingAs($this->service->client);

        $this->get(route('client.services.change-plan', $this->service))
            ->assertOk()
            ->assertSee('Starter')
            ->assertDontSee('goes back to your wallet');

        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->starter->id])->assertRedirect();

        $change = PlanChange::query()->sole();
        $this->assertSame(PlanChange::MODE_RENEWAL, $change->mode);
        $this->assertTrue($change->apply_on->isSameDay('2026-10-16'));
        $this->assertSame($this->business->id, $this->service->fresh()->product_id);
        $this->assertSame(0, $this->service->client->fresh()->credit);
    }
}
