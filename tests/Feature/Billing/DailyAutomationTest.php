<?php

namespace Tests\Feature\Billing;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class DailyAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewal_invoices_are_created_once_per_period(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        Service::factory()->count(2)->create(['client_id' => $client->id, 'recurring_amount' => 1000, 'next_due_date' => today()->addDays(5)]);
        Service::factory()->create(['recurring_amount' => 500, 'next_due_date' => today()->addDays(30)]);

        $this->artisan('nuvabill:cron')->assertSuccessful();
        $this->artisan('nuvabill:cron')->assertSuccessful();

        $invoices = Invoice::query()->where('client_id', $client->id)->get();
        $this->assertCount(1, $invoices, 'Two services due on the same day share one invoice, and running twice adds nothing.');
        $this->assertSame(2000, $invoices->first()->total);
        $this->assertTrue($invoices->first()->due_at->equalTo(today()->addDays(5)));
        $this->assertSame(1, Invoice::query()->count());
        $this->assertNotNull(setting('automation.last_run_at'));
        Mail::assertSent(TemplatedMessage::class, 1);
    }

    public function test_paying_a_renewal_moves_the_next_due_date_forward(): void
    {
        $service = Service::factory()->create(['recurring_amount' => 1000, 'next_due_date' => today()->addDays(3)]);

        $this->artisan('nuvabill:cron');
        $invoice = Invoice::query()->firstOrFail();

        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer');

        $this->assertTrue($service->fresh()->next_due_date->equalTo(today()->addDays(3)->addMonthNoOverflow()));
    }

    public function test_overdue_services_are_suspended_and_come_back_when_paid(): void
    {
        Http::fake(['*' => Http::response(['metadata' => ['result' => 1, 'reason' => 'OK']])]);
        $this->setSettings(['automation.suspend_days' => 3, 'automation.reminder_days' => [1, 3]]);

        $server = Server::factory()->create();
        $service = Service::factory()->create([
            'product_id' => Product::factory()->cpanel($server)->create()->id,
            'server_id' => $server->id,
            'username' => 'late1',
            'recurring_amount' => 1000,
            'next_due_date' => today()->subDays(4),
        ]);

        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'due_at' => today()->subDays(4), 'total' => 1000]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => 'Renewal', 'amount' => 1000, 'period_start' => today()->subDays(4)]);

        Mail::fake();
        $this->artisan('nuvabill:cron')->assertSuccessful();

        $service->refresh();
        $this->assertSame(ServiceStatus::Suspended, $service->status);
        $this->assertSame('Overdue on payment', $service->suspension_reason);
        $this->assertSame(2, $invoice->fresh()->reminder_count);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail) => str_contains($mail->subjectLine, 'overdue'));
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'suspendacct') && $request['user'] === 'late1');

        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'unsuspendacct'));
    }

    public function test_services_suspended_by_staff_stay_suspended_after_payment(): void
    {
        $service = Service::factory()->suspended('Abuse report')->create();
        $invoice = Invoice::factory()->create(['client_id' => $service->client_id]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => 'Renewal', 'amount' => 1000]);

        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer');

        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_automation_does_nothing_when_turned_off(): void
    {
        $this->setSettings(['automation.enabled' => false]);
        Service::factory()->create(['next_due_date' => today()]);

        $this->artisan('nuvabill:cron')->assertSuccessful();

        $this->assertSame(0, Invoice::query()->count());
    }
}
