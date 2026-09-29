<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
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
 * Upgrades and downgrades: a fair price for the days left, invoices, wallet credit, changes on the
 * next renewal, and staff changes.
 */
class PlanChangeTest extends TestCase
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

    public function test_an_upgrade_is_invoiced_for_the_days_left_and_happens_when_paid(): void
    {
        Mail::fake();
        $this->actingAs($this->service->client);

        $this->get(route('client.services.change-plan', $this->service))->assertOk()->assertSee('Business')->assertSee('$5.00');

        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->business->id])->assertRedirect();

        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->assertSame(500, $invoice->total, 'Half a month of $20 minus half a month of $10.');
        $this->assertSame(InvoiceItem::TYPE_PLAN_CHANGE, $invoice->items->first()->type);
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id, 'Nothing changes before the invoice is paid.');

        app(PaymentRecorder::class)->record($invoice, 500, 'banktransfer');

        $service = $this->service->fresh();
        $this->assertSame($this->business->id, $service->product_id);
        $this->assertSame(2000, $service->recurring_amount);
        $this->assertSame(PlanChange::STATUS_APPLIED, PlanChange::query()->sole()->status);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'Your plan is now Business'));
    }

    public function test_a_downgrade_happens_now_and_credits_the_unused_amount(): void
    {
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);
        $this->actingAs($this->service->client);

        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->starter->id])->assertRedirect(route('client.services.show', $this->service));

        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
        $this->assertSame(500, $this->service->client->fresh()->credit);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_a_downgrade_can_wait_for_the_next_renewal_which_bills_the_new_plan(): void
    {
        $this->setSettings(['billing.downgrade' => 'renewal']);
        $this->service->update(['product_id' => $this->business->id, 'recurring_amount' => 2000]);

        $change = app(PlanChanges::class)->start($this->service->fresh(), $this->starter);

        $this->assertSame(PlanChange::MODE_RENEWAL, $change->mode);
        $this->assertTrue($change->apply_on->isSameDay('2026-10-16'));
        $this->assertSame($this->business->id, $this->service->fresh()->product_id);

        app(RenewalGenerator::class)->generate(Carbon::parse('2026-10-10'));
        $renewal = Invoice::query()->sole();
        $this->assertSame(1000, $renewal->total, 'The renewal already bills the cheaper plan.');
        $this->assertStringStartsWith('Starter', $renewal->items->first()->description);

        $this->assertSame(0, app(PlanChanges::class)->applyScheduled(Carbon::parse('2026-10-15')));
        $this->assertSame(1, app(PlanChanges::class)->applyScheduled(Carbon::parse('2026-10-16')));
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
        $this->assertSame(0, $this->service->client->fresh()->credit, 'No money back when the change waits for the renewal.');
    }

    public function test_clients_only_move_to_the_plans_staff_allowed_and_not_with_an_open_invoice(): void
    {
        $other = Product::factory()->priced(3000)->create();
        $this->actingAs($this->service->client);

        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $other->id])->assertSessionHas('error');

        $invoice = Invoice::factory()->create(['client_id' => $this->service->client_id, 'status' => InvoiceStatus::Unpaid]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $this->service->id, 'description' => 'Renewal', 'amount' => 1000]);

        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->business->id])
            ->assertSessionHas('error', 'Pay the open invoice for this service first.');
        $this->assertSame(0, PlanChange::query()->count());

        $stranger = Client::factory()->create();
        $this->actingAs($stranger)->get(route('client.services.change-plan', $this->service))->assertNotFound();
    }

    public function test_stopping_a_change_or_cancelling_its_invoice_leaves_the_plan_alone(): void
    {
        $this->actingAs($this->service->client);
        $this->post(route('client.services.change-plan.store', $this->service), ['product_id' => $this->business->id]);

        $this->delete(route('client.services.change-plan.destroy', $this->service))->assertRedirect();
        $this->assertSame(PlanChange::STATUS_CANCELLED, PlanChange::query()->sole()->status);
        $this->assertSame(InvoiceStatus::Cancelled, Invoice::query()->sole()->status);

        $second = app(PlanChanges::class)->start($this->service->fresh(), $this->business);
        app(InvoiceManager::class)->cancel($second->invoice);

        $this->assertSame(PlanChange::STATUS_CANCELLED, $second->fresh()->status);
        $this->assertSame($this->starter->id, $this->service->fresh()->product_id);
    }

    public function test_staff_can_change_any_plan_with_the_same_server_without_charging(): void
    {
        $other = Product::factory()->priced(3000)->create(['name' => 'Pro']);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.services.show', $this->service))->assertOk()->assertSee('Pro (+$10.00)');

        $this->post(route('admin.services.change-plan', $this->service), ['product_id' => $other->id, 'charge' => 0])
            ->assertSessionHas('status', 'The plan was changed.');

        $this->assertSame($other->id, $this->service->fresh()->product_id);
        $this->assertSame(3000, $this->service->fresh()->recurring_amount);
        $this->assertSame(0, Invoice::query()->count());
    }

    public function test_the_change_plan_page_lists_every_allowed_plan(): void
    {
        $pro = Product::factory()->priced(3000)->create(['name' => 'Pro']);
        $cheap = Product::factory()->priced(500)->create(['name' => 'Mini']);
        $this->starter->update(['upgrade_product_ids' => [$this->business->id, $pro->id, $cheap->id]]);
        $this->actingAs($this->service->client);

        $this->get(route('client.services.show', $this->service))->assertOk()->assertSee('Upgrade or downgrade');

        $this->get(route('client.services.change-plan', $this->service))
            ->assertOk()
            ->assertSeeInOrder(['Business', '$5.00', 'Mini', '$2.50', 'Pro', '$10.00']);
    }

    public function test_clients_cannot_change_plans_when_it_is_turned_off(): void
    {
        $this->setSettings(['billing.plan_changes' => false]);
        $this->actingAs($this->service->client);

        $this->get(route('client.services.show', $this->service))->assertOk()->assertDontSee('Upgrade or downgrade');
        $this->get(route('client.services.change-plan', $this->service))->assertNotFound();
    }
}
