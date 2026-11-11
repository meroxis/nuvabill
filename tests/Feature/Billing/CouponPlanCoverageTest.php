<?php

namespace Tests\Feature\Billing;

use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\RenewalGenerator;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A coupon that keeps its discount on renewals gives it only while the service is on a plan and
 * billing cycle the coupon is made for.
 */
class CouponPlanCoverageTest extends TestCase
{
    use RefreshDatabase;

    private Product $starter;

    private Product $business;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        // 15 of the 30 days in this period are left.
        $this->travelTo(Carbon::parse('2026-10-01 10:00'));
        $this->setSettings(['wallet.enabled' => true]);

        $this->starter = Product::factory()->priced(1000)->create(['name' => 'Starter']);
        $this->business = Product::factory()->priced(2000)->create(['name' => 'Business']);
        $this->starter->update(['upgrade_product_ids' => [$this->business->id]]);
        $this->business->update(['upgrade_product_ids' => [$this->starter->id]]);
        $this->client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD']);
    }

    public function test_a_renewal_on_a_plan_the_coupon_is_made_for_keeps_the_discount(): void
    {
        $coupon = $this->couponFor($this->starter);
        $service = $this->serviceOn($this->starter, $coupon);

        $renewal = $this->renew($service);

        $this->assertSame(500, $renewal->total);
        $this->assertSame(-500, $renewal->items->firstWhere('type', InvoiceItem::TYPE_DISCOUNT)->amount);
        $this->assertSame(1, $coupon->redemptions()->count());
    }

    public function test_after_a_paid_move_to_another_plan_the_renewal_is_full_price(): void
    {
        $coupon = $this->couponFor($this->starter);
        $service = $this->serviceOn($this->starter, $coupon);

        $this->actingAs($this->client, 'web')
            ->get(route('client.services.change-plan', $service))
            ->assertOk()
            ->assertSee('Coupon HALF is not for this plan, so it gives no discount there.');

        $this->post(route('client.services.change-plan.store', $service), ['product_id' => $this->business->id])->assertRedirect();
        $upgrade = Invoice::query()->latest('id')->firstOrFail();
        app(PaymentRecorder::class)->record($upgrade, $upgrade->total, 'banktransfer');
        $this->assertSame($this->business->id, $service->fresh()->product_id);

        $renewal = $this->renew($service);

        $this->assertSame(2000, $renewal->total, 'The bigger plan renews at its own price.');
        $this->assertNull($renewal->items->firstWhere('type', InvoiceItem::TYPE_DISCOUNT));
        $this->assertSame(0, $coupon->redemptions()->count());
        $this->assertSame($coupon->id, $service->fresh()->coupon_id, 'The coupon stays on the service for a move back.');

        $this->get(route('client.services.show', $service))->assertOk()->assertSee('not for your current plan')->assertDontSee('50% off');
        $this->signInAdmin();
        $this->get(route('admin.services.show', $service))->assertOk()->assertSee('not for this plan, so renewals get no discount');
    }

    public function test_payments_left_are_not_used_up_on_another_plan_and_count_again_after_a_move_back(): void
    {
        $coupon = $this->couponFor($this->starter, ['recurring' => Coupon::RECURRING_COUNT, 'recurring_count' => 3]);
        $service = $this->serviceOn($this->business, $coupon, ['coupon_payments_left' => 2]);

        $this->assertSame(2000, $this->renew($service)->total);
        $this->assertSame(2, $service->fresh()->coupon_payments_left);

        $service->update(['product_id' => $this->starter->id, 'recurring_amount' => 1000, 'next_due_date' => '2026-11-16']);

        $this->assertSame(500, $this->renew($service->fresh(), '2026-11-10')->total);
        $this->assertSame(1, $service->fresh()->coupon_payments_left);
    }

    public function test_a_move_planned_for_the_renewal_is_checked_against_the_plan_it_bills(): void
    {
        $this->setSettings(['billing.downgrade' => 'renewal']);
        $coupon = $this->couponFor($this->business);
        $service = $this->serviceOn($this->business, $coupon);

        $change = app(PlanChanges::class)->start($service->fresh(), $this->starter);
        $this->assertSame(PlanChange::MODE_RENEWAL, $change->mode);

        $renewal = $this->renew($service);

        $this->assertStringStartsWith('Starter', $renewal->items->first()->description);
        $this->assertSame(1000, $renewal->total, 'The coupon is for Business only, and this renewal bills Starter.');
    }

    public function test_a_move_planned_to_a_plan_the_coupon_is_made_for_gets_the_discount(): void
    {
        $this->setSettings(['billing.downgrade' => 'renewal']);
        $coupon = $this->couponFor($this->starter);
        $service = $this->serviceOn($this->business, $coupon);

        app(PlanChanges::class)->start($service->fresh(), $this->starter);

        $this->assertSame(500, $this->renew($service)->total);
    }

    public function test_a_coupon_for_another_billing_cycle_gives_no_renewal_discount(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'YEARLY', 'value' => 50, 'recurring' => Coupon::RECURRING_EVERY, 'billing_cycles' => ['annually']]);
        $service = $this->serviceOn($this->starter, $coupon);

        $this->assertSame(1000, $this->renew($service)->total);
    }

    public function test_a_coupon_for_every_plan_keeps_its_discount_after_a_move(): void
    {
        $coupon = Coupon::factory()->create(['code' => 'ANYPLAN', 'value' => 50, 'recurring' => Coupon::RECURRING_EVERY]);
        $service = $this->serviceOn($this->business, $coupon);

        $this->assertSame(1000, $this->renew($service)->total);
        $this->actingAs($this->client, 'web')->get(route('client.services.show', $service))->assertOk()->assertSee('50% off')->assertDontSee('not for your current plan');
    }

    /**
     * Half price on every payment, for one plan only.
     *
     * @param  array<string, mixed>  $rules
     */
    private function couponFor(Product $product, array $rules = []): Coupon
    {
        return Coupon::factory()->create($rules + ['code' => 'HALF', 'value' => 50, 'recurring' => Coupon::RECURRING_EVERY, 'product_ids' => [$product->id]]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function serviceOn(Product $product, Coupon $coupon, array $values = []): Service
    {
        return Service::factory()->create($values + [
            'client_id' => $this->client->id,
            'product_id' => $product->id,
            'recurring_amount' => $product->is($this->starter) ? 1000 : 2000,
            'next_due_date' => '2026-10-16',
            'coupon_id' => $coupon->id,
        ]);
    }

    /**
     * The renewal invoice made for the next period of the service.
     */
    private function renew(Service $service, string $on = '2026-10-10'): Invoice
    {
        $before = Invoice::query()->max('id') ?? 0;
        app(RenewalGenerator::class)->generate(Carbon::parse($on));

        return Invoice::query()
            ->where('id', '>', $before)
            ->whereHas('items', fn ($query) => $query->where('service_id', $service->id)->where('type', InvoiceItem::TYPE_SERVICE))
            ->sole();
    }
}
