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
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PaymentIntent;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;
use RuntimeException;
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

    public function test_an_upgrade_paid_while_the_renewal_run_looks_at_it_bills_the_new_plan(): void
    {
        $upgrade = $this->requestUpgrade();
        $paid = false;

        // The payment lands just after the run found the upgrade still waiting.
        PlanChange::retrieved(function () use (&$paid, $upgrade): void {
            if (! $paid) {
                $paid = true;
                app(PaymentRecorder::class)->record($upgrade, 500, 'banktransfer');
            }
        });

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $this->assertTrue($paid);
        $this->assertSame(InvoiceStatus::Paid, $upgrade->fresh()->status);
        $this->assertSame($this->business->id, $this->service->fresh()->product_id);
        $this->assertSame(2000, Invoice::query()->whereKeyNot($upgrade->id)->sole()->total, 'The renewal bills the plan the payment moved the service to.');
    }

    public function test_a_part_paid_upgrade_is_stopped_and_the_part_goes_back_to_the_wallet(): void
    {
        $upgrade = $this->requestUpgrade();
        $payment = app(PaymentRecorder::class)->record($upgrade, 200, 'banktransfer');

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $this->assertSame(InvoiceStatus::Cancelled, $upgrade->fresh()->status, 'The service is not left overdue on it.');
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);

        // Given back once, with a refund line that matches the payment on the cancelled invoice.
        $this->assertSame(200, CreditTransaction::query()->where('amount', '>', 0)->sole()->amount);
        $this->assertSame("Returned from cancelled invoice {$upgrade->number}", CreditTransaction::query()->where('amount', '>', 0)->sole()->description);
        $refund = Transaction::query()->where('invoice_id', $upgrade->id)->where('type', 'refund')->sole();
        $this->assertSame(-200, $refund->amount);
        $this->assertSame($payment->id, $refund->meta['refund_of']);

        // The money is the client's: in the wallet, or already used on the renewal.
        $renewal = Invoice::query()->whereKeyNot($upgrade->id)->sole();
        $this->assertSame(1000, $renewal->total);
        $this->assertSame(200, $renewal->amount_paid + $this->service->client->fresh()->credit);
    }

    public function test_a_part_paid_upgrade_goes_back_once_when_the_wallet_is_off(): void
    {
        $this->setSettings(['wallet.enabled' => false, 'wallet.auto_apply' => false]);
        $upgrade = $this->requestUpgrade();
        app(PaymentRecorder::class)->record($upgrade, 200, 'banktransfer');

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $this->assertSame(InvoiceStatus::Cancelled, $upgrade->fresh()->status);
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);

        // Cancelling the invoice gave it back, like any cancelled invoice, so staff are not also
        // asked to pay it back by hand.
        $this->assertSame(200, $this->service->client->fresh()->credit);
        $this->assertSame(1, CreditTransaction::query()->count());
        $this->assertFalse(ActivityLog::query()->where('action', 'service.plan_change_refund')->exists());
        $this->assertStringContainsString('$2.00 paid on it went back to the wallet', ActivityLog::query()->where('action', 'service.plan_change_expired')->sole()->description);
    }

    public function test_a_part_paid_upgrade_the_wallet_cannot_take_back_does_not_stop_the_nightly_run(): void
    {
        // The client's wallet is in euros, the service is billed in dollars, and no rate is set.
        $this->service->client->update(['currency' => 'EUR']);
        $upgrade = $this->requestUpgrade();
        app(PaymentRecorder::class)->record($upgrade, 200, 'banktransfer');
        $raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null, 'currency' => 'USD']);
        $overdue = app(InvoiceManager::class)->create($raz, [['description' => 'Hosting', 'amount' => 900]], dueAt: Carbon::parse('2026-10-01'));

        $this->travelTo(Carbon::parse('2026-10-09 01:00'));
        $this->assertIsArray(app(DailyAutomation::class)->run());

        // The other steps ran, and the service renews on the plan it is on.
        $this->assertSame(3, $overdue->fresh()->reminder_count);
        $renewal = Invoice::query()->whereKeyNot([$upgrade->id, $overdue->id])->sole();
        $this->assertSame(1000, $renewal->total);
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);

        // The upgrade stays open with its money on it, and staff are told once.
        $this->assertSame(InvoiceStatus::Unpaid, $upgrade->fresh()->status);
        $this->assertSame(200, $upgrade->fresh()->amount_paid);
        $this->assertSame(PlanChange::STATUS_PENDING, PlanChange::query()->sole()->status);
        $this->assertSame(0, $this->service->client->fresh()->credit);

        $this->travelTo(Carbon::parse('2026-10-10 01:00'));
        $this->assertIsArray(app(DailyAutomation::class)->run());
        $this->assertStringContainsString("could not stop before the renewal, so its invoice {$upgrade->number} stays open", ActivityLog::query()->where('action', 'service.plan_change_stuck')->sole()->description);

        // Once staff add a rate, the next run stops it and gives the money back, converted.
        $this->setSettings(['currency.rates' => ['EUR' => 0.9]]);
        $this->travelTo(Carbon::parse('2026-10-11 01:00'));
        app(DailyAutomation::class)->run();

        $this->assertSame(InvoiceStatus::Cancelled, $upgrade->fresh()->status);
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame(180, $this->service->client->fresh()->credit);
    }

    public function test_an_upgrade_that_cannot_be_settled_does_not_stop_the_renewals(): void
    {
        $this->mock(PlanChanges::class, function (MockInterface $mock): void {
            $mock->shouldReceive('settleBeforeRenewal')->andThrow(new RuntimeException('Something broke'));
            $mock->shouldReceive('scheduledFor')->andReturnNull();
        });

        $this->assertSame(1, app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09')));

        $this->assertSame(1000, Invoice::query()->sole()->total);
        $this->assertSame("Pending plan change for service #{$this->service->id} could not be settled before the renewal: Something broke", ActivityLog::query()->where('action', 'service.plan_change_failed')->sole()->description);
    }

    public function test_an_upgrade_kept_open_for_a_payment_that_never_lands_stops_the_next_night(): void
    {
        // Asked for 9 days before the due date, so the upgrade alone would not suspend the service yet.
        $this->travelTo(Carbon::parse('2026-10-07 10:00'));
        $this->actingAs($this->service->client)
            ->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->business->id])
            ->assertRedirect();
        $upgrade = Invoice::query()->sole();

        // A gateway payment started on the evening of 8 Oct, then left.
        $this->travelTo(Carbon::parse('2026-10-08 20:00'));
        PaymentIntent::query()->create(['invoice_id' => $upgrade->id, 'gateway' => 'wayl', 'reference' => 'wayl_left', 'amount' => $upgrade->total, 'currency' => 'USD', 'status' => PaymentIntent::STATUS_PENDING]);

        $this->travelTo(Carbon::parse('2026-10-09 01:00'));
        app(DailyAutomation::class)->run();
        $this->assertSame(InvoiceStatus::Unpaid, $upgrade->fresh()->status, 'Kept open while its payment may still land.');
        $this->assertSame(PlanChange::STATUS_PENDING, PlanChange::query()->sole()->status);

        foreach (['2026-10-10', '2026-10-11', '2026-10-12'] as $night) {
            $this->travelTo(Carbon::parse("{$night} 01:00"));
            app(DailyAutomation::class)->run();

            $this->assertSame(InvoiceStatus::Cancelled, $upgrade->fresh()->status);
            $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
            $this->assertSame(ServiceStatus::Active, $this->service->fresh()->status, "Not suspended over the stale upgrade on {$night}.");
        }

        $this->assertSame(1000, Invoice::query()->whereKeyNot($upgrade->id)->sole()->total);
    }

    public function test_an_upgrade_with_a_payment_going_through_stays_open_and_is_given_back_when_it_lands(): void
    {
        $upgrade = $this->requestUpgrade();
        PaymentIntent::query()->create(['invoice_id' => $upgrade->id, 'gateway' => 'stripe', 'reference' => 'pi_late', 'amount' => 500, 'currency' => 'USD', 'status' => PaymentIntent::STATUS_PENDING]);

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $this->assertSame(InvoiceStatus::Unpaid, $upgrade->fresh()->status, 'Not cancelled while its payment may still land.');
        $this->assertSame(1000, Invoice::query()->whereKeyNot($upgrade->id)->sole()->total);

        app(PaymentRecorder::class)->record($upgrade, 500, 'stripe', 'pi_late');

        $this->assertUpgradeStoppedAndPaidBack($upgrade);
    }

    public function test_a_downgrade_to_a_free_plan_renews_without_going_overdue(): void
    {
        $free = Product::factory()->priced(0)->create(['name' => 'Free']);
        $this->business->update(['upgrade_product_ids' => [$this->starter->id, $free->id]]);
        $this->setSettings(['billing.downgrade' => 'renewal']);
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);

        $this->assertSame(PlanChange::MODE_RENEWAL, app(PlanChanges::class)->start($this->service->fresh(), $free)->mode);

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-09'));

        $renewal = Invoice::query()->sole();
        $this->assertSame(0, $renewal->total);
        $this->assertSame(InvoiceStatus::Paid, $renewal->status, 'Nothing to pay, so it is settled at once.');
        $this->assertTrue($this->service->fresh()->next_due_date->isSameDay('2026-11-16'));

        $this->travelTo(Carbon::parse('2026-10-25 03:00'));
        app(DailyAutomation::class)->run();

        $service = $this->service->fresh();
        $this->assertSame($free->id, $service->product_id);
        $this->assertSame(ServiceStatus::Active, $service->status);
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
