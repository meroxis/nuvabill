<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\InvoiceManager;
use App\Billing\RenewalGenerator;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Jobs that run twice, or two runs at the same time, never bill anyone twice.
 */
class RunTwiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_run_waits_while_one_is_busy(): void
    {
        Mail::fake();
        Service::factory()->create(['recurring_amount' => 1000, 'next_due_date' => today()->addDays(2)]);
        $busy = Cache::lock(DailyAutomation::LOCK, 60);
        $busy->get();

        $this->assertNull(app(DailyAutomation::class)->run());
        $this->artisan('nuvabill:cron')->expectsOutputToContain('Another automation run is busy')->assertSuccessful();
        $this->assertSame(0, Invoice::query()->count());
        $this->assertNull(setting('automation.last_run_at'));

        $busy->release();
        $this->artisan('nuvabill:cron')->assertSuccessful();
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_the_database_refuses_a_second_invoice_for_a_period_even_when_two_runs_meet(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $service = Service::factory()->create(['client_id' => $client->id, 'recurring_amount' => 1000, 'next_due_date' => today()->addDays(2)]);

        // Another run is half-way: it holds the period's key but its line is not a renewal line yet,
        // so this run's own "already invoiced?" check does not see it.
        app(InvoiceManager::class)->create($client, [[
            'description' => 'Made by the other run',
            'amount' => 1000,
            'billing_key' => RenewalGenerator::billingKey('service', $service->id, $service->next_due_date),
        ]]);

        $this->assertSame(0, app(RenewalGenerator::class)->generate());
        $this->assertSame(1, Invoice::query()->count());
        Mail::assertNothingSent();
    }

    public function test_a_cancelled_renewal_can_be_invoiced_again(): void
    {
        Mail::fake();
        Service::factory()->create(['recurring_amount' => 1000, 'next_due_date' => today()->addDays(2)]);

        app(RenewalGenerator::class)->generate();
        app(InvoiceManager::class)->cancel(Invoice::query()->firstOrFail());

        $this->assertSame(1, app(RenewalGenerator::class)->generate());
        $this->assertSame(1, Invoice::query()->where('status', InvoiceStatus::Unpaid)->count());
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_renewing_a_domain_twice_gives_the_same_invoice(): void
    {
        Mail::fake();
        $domain = Domain::factory()->create(['recurring_amount' => 1500, 'next_due_date' => today()->addMonths(2)]);

        $first = app(RenewalGenerator::class)->invoiceDomainRenewal($domain);
        $second = app(RenewalGenerator::class)->invoiceDomainRenewal($domain->fresh());

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_each_reminder_is_sent_once_however_often_the_job_runs(): void
    {
        $this->setSettings(['automation.reminder_days' => [1, 5]]);
        $invoice = Invoice::factory()->create(['due_at' => today()->subDays(2), 'total' => 1000]);

        Mail::fake();
        $this->artisan('nuvabill:cron');
        $this->artisan('nuvabill:cron');
        $this->artisan('nuvabill:cron');

        Mail::assertSent(TemplatedMessage::class, 1);
        $this->assertSame(1, $invoice->fresh()->reminder_count);
    }

    public function test_paying_from_the_wallet_twice_takes_the_money_once(): void
    {
        $this->setSettings(['wallet.enabled' => true]);
        $client = Client::factory()->create(['credit' => 5000]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'currency' => $client->currency, 'total' => 3000]);

        $wallet = app(Wallet::class);
        $this->assertSame(3000, $wallet->pay($invoice));
        $this->assertSame(0, $wallet->pay($invoice));

        $this->assertSame(2000, $client->fresh()->credit);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(1, Transaction::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(1, CreditTransaction::query()->where('client_id', $client->id)->count());
    }
}
