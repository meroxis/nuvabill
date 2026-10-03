<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceManager;
use App\Billing\LineItems;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A service ordered at the end of a month keeps renewing on that day after a short month, so
 * 31 Jan, 28 Feb, 31 Mar, 30 Apr and not 28 Mar, 28 Apr for good.
 */
class BillingAnniversaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_a_service_started_on_the_31st_renews_on_the_last_day_of_each_month(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-01-31 10:00:00'));
        $service = $this->service(['registration_date' => '2027-01-31', 'next_due_date' => '2027-01-31']);

        // The first invoice, as at order time.
        $first = app(InvoiceManager::class)->create($service->client, [LineItems::servicePeriod($service, CarbonImmutable::parse('2027-01-31'), 1000)]);
        $this->assertSame('2027-02-27', $first->items->sole()->period_end->toDateString());
        $this->pay($first);
        $this->assertSame('2027-02-28', $service->fresh()->next_due_date->toDateString());

        $this->travelTo(CarbonImmutable::parse('2027-02-21 10:00:00'));
        $line = $this->renewAndPay();
        $this->assertSame('2027-02-28', $line->period_start->toDateString());
        $this->assertSame('2027-03-30', $line->period_end->toDateString(), 'The period ends the day before the next due date, so no day is left unbilled.');
        $this->assertSame('2027-03-31', $service->fresh()->next_due_date->toDateString());

        $this->travelTo(CarbonImmutable::parse('2027-03-24 10:00:00'));
        $line = $this->renewAndPay();
        $this->assertSame('2027-04-29', $line->period_end->toDateString());
        $this->assertSame('2027-04-30', $service->fresh()->next_due_date->toDateString());

        $this->travelTo(CarbonImmutable::parse('2027-04-23 10:00:00'));
        $this->renewAndPay();
        $this->assertSame('2027-05-31', $service->fresh()->next_due_date->toDateString());
    }

    public function test_a_due_date_staff_moved_to_mid_month_stays_there(): void
    {
        $this->travelTo(CarbonImmutable::parse('2027-03-10 10:00:00'));
        $service = $this->service(['registration_date' => '2027-01-31', 'next_due_date' => '2027-03-15']);

        $line = $this->renewAndPay();

        $this->assertSame('2027-04-14', $line->period_end->toDateString());
        $this->assertSame('2027-04-15', $service->fresh()->next_due_date->toDateString());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function service(array $attributes): Service
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'company_name' => null, 'currency' => 'USD']);

        return Service::factory()->for($client)->create($attributes + ['recurring_amount' => 1000]);
    }

    private function renewAndPay(): InvoiceItem
    {
        $this->assertSame(1, app(RenewalGenerator::class)->generate());
        $invoice = Invoice::query()->latest('id')->firstOrFail();
        $this->pay($invoice);

        return $invoice->items()->where('type', InvoiceItem::TYPE_SERVICE)->sole();
    }

    private function pay(Invoice $invoice): void
    {
        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'banktransfer');
    }
}
