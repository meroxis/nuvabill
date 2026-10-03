<?php

namespace Tests\Feature\Billing;

use App\Automation\DailyAutomation;
use App\Billing\InvoiceManager;
use App\Billing\InvoicePaidHandler;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Events\ServiceSuspended;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Service;
use App\Support\Activity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Tests\TestCase;

/**
 * Long text (a server's or gateway's full error, a long domain) fits its column. MySQL refuses text
 * that is too long, so one long value must not stop the nightly run. SQLite in the tests does not
 * check lengths, so the tests check them.
 */
class LongTextFitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_activity_descriptions_are_cut_to_the_column(): void
    {
        $long = Activity::log('test.long', str_repeat('a', 400));
        $multibyte = Activity::log('test.long', str_repeat('ب', 300));
        $short = Activity::log('test.short', 'Service #1 suspended');

        $this->assertSame(255, mb_strlen($long->fresh()->description));
        $this->assertStringEndsWith('…', $long->fresh()->description);
        $this->assertSame(255, mb_strlen($multibyte->fresh()->description));
        $this->assertSame('Service #1 suspended', $short->fresh()->description);
    }

    public function test_a_long_decline_message_is_cut_and_the_try_is_saved(): void
    {
        $this->autoPaySetUp();
        $decline = str_repeat('The provided PaymentMethod was previously used and may not be used again. ', 4);
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['code' => 'payment_method_unexpected_state', 'message' => $decline]], 400)]);
        $client = $this->client('Raz', 'raz@example.test');
        $this->saveCard($client, 'cus_raz');
        $invoice = $this->renewalInvoice($client);

        $summary = app(DailyAutomation::class)->run();

        $this->assertSame(1, $summary['charge_failed']);
        $invoice->refresh();
        $this->assertSame(1, $invoice->autopay_attempts);
        $this->assertNotNull($invoice->autopay_retry_at);
        $this->assertLessThanOrEqual(255, mb_strlen((string) ActivityLog::query()->where('action', 'invoice.autopay_failed')->value('description')));
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('raz@example.test'));
    }

    public function test_an_invoice_whose_failure_cannot_be_logged_keeps_its_try_and_the_others_are_still_charged(): void
    {
        $this->autoPaySetUp();
        Http::fake(fn (Request $request) => $request['customer'] === 'cus_raz'
            ? Http::response(['error' => ['code' => 'card_declined', 'message' => 'Your card was declined.']], 402)
            : Http::response(['id' => 'pi_merlas', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd']));

        $raz = $this->client('Raz', 'raz@example.test');
        $this->saveCard($raz, 'cus_raz');
        $declined = $this->renewalInvoice($raz);
        $merLas = $this->client('Mer Las', 'merlas@example.test');
        $this->saveCard($merLas, 'cus_merlas');
        $paid = $this->renewalInvoice($merLas);

        // As on MySQL when a log line is too long: the insert fails.
        ActivityLog::creating(function (ActivityLog $log): void {
            if ($log->action === 'invoice.autopay_failed') {
                throw new RuntimeException('Data too long for column description');
            }
        });

        $summary = app(DailyAutomation::class)->run();

        $this->assertSame(1, $summary['charge_failed']);
        $this->assertSame(1, $summary['charged']);
        $declined->refresh();
        $this->assertSame(1, $declined->autopay_attempts, 'The try is saved first, so the next run does not repeat it.');
        $this->assertNotNull($declined->autopay_retry_at);
        $this->assertSame(InvoiceStatus::Paid, $paid->fresh()->status);
    }

    public function test_one_service_that_fails_does_not_stop_the_other_suspensions_and_terminations(): void
    {
        $this->setSettings(['automation.suspend_days' => 3, 'automation.terminate_days' => 30, 'automation.reminder_days' => []]);
        $first = $this->overdueService(4);
        $second = $this->overdueService(4);
        $third = $this->overdueService(31, ['status' => 'suspended', 'suspended_at' => now()->subDays(20), 'suspension_reason' => InvoicePaidHandler::OVERDUE_REASON]);

        Event::listen(ServiceSuspended::class, function (ServiceSuspended $event) use ($first): void {
            if ($event->service->is($first)) {
                throw new RuntimeException('Data too long for column description');
            }
        });

        $summary = app(DailyAutomation::class)->run();

        $this->assertIsArray($summary);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(1, $summary['suspended']);
        $this->assertSame(1, $summary['terminated']);
        $this->assertSame('suspended', $second->fresh()->status->value);
        $this->assertSame('terminated', $third->fresh()->status->value);
    }

    public function test_renewal_lines_with_a_long_name_and_domain_fit_and_keep_their_period(): void
    {
        $domain = str_repeat('a', 63).'.'.str_repeat('b', 63).'.'.str_repeat('c', 58).'.com';
        $this->assertSame(190, mb_strlen($domain));
        $product = Product::factory()->create(['name' => str_repeat('X', 60)]);
        $service = Service::factory()->for($this->client('Raz', 'raz@example.test'))->create([
            'product_id' => $product->id,
            'domain' => $domain,
            'recurring_amount' => 1000,
            'next_due_date' => today()->addDays(3),
        ]);
        $service->addons()->create(['name' => str_repeat('Y', 120), 'status' => 'active', 'recurring_amount' => 200]);

        $this->assertSame(1, app(RenewalGenerator::class)->generate());

        $items = Invoice::query()->sole()->items;
        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertLessThanOrEqual(255, mb_strlen($item->description));
            $this->assertMatchesRegularExpression('/ \(\d{2} \w{3} \d{4} - \d{2} \w{3} \d{4}\)$/', $item->description, 'The period stays whole.');
        }

        $this->assertStringStartsWith(str_repeat('X', 60).' - aaa', $items->firstWhere('type', 'service')->description);
    }

    public function test_one_renewal_invoice_that_cannot_be_saved_does_not_stop_the_others(): void
    {
        $raz = $this->client('Raz', 'raz@example.test');
        $merLas = $this->client('Mer Las', 'merlas@example.test');
        Service::factory()->for($raz)->create(['recurring_amount' => 1000, 'next_due_date' => today()->addDays(3)]);
        Service::factory()->for($merLas)->create(['recurring_amount' => 1000, 'next_due_date' => today()->addDays(3)]);

        Invoice::creating(function (Invoice $invoice) use ($raz): void {
            if ($invoice->client_id === $raz->id) {
                throw new RuntimeException('Data too long for column description');
            }
        });

        $this->assertSame(1, app(RenewalGenerator::class)->generate());
        $this->assertSame([$merLas->id], Invoice::query()->pluck('client_id')->all());
    }

    private function autoPaySetUp(): void
    {
        Carbon::setTestNow('2026-10-12 00:15:00');
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
    }

    private function client(string $name, string $email): Client
    {
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, '');

        return Client::factory()->create(['first_name' => $first, 'last_name' => $last, 'company_name' => null, 'email' => $email, 'currency' => 'USD']);
    }

    private function saveCard(Client $client, string $customer): PaymentMethod
    {
        return PaymentMethod::query()->create([
            'client_id' => $client->id, 'gateway' => 'stripe', 'type' => PaymentMethod::TYPE_CARD, 'reference' => 'pm_'.$customer,
            'customer_reference' => $customer, 'brand' => 'visa', 'last4' => '4242', 'expires_month' => 8, 'expires_year' => 2028, 'is_default' => true,
        ]);
    }

    private function renewalInvoice(Client $client): Invoice
    {
        return app(InvoiceManager::class)->create($client, [[
            'description' => 'Business Hosting',
            'amount' => 1299,
            'billing_key' => 'service-'.$client->id.'-'.today()->toDateString(),
        ]], dueAt: today(), currency: 'USD');
    }

    /**
     * An Active service (no server module) with an unpaid renewal that was due $days ago.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function overdueService(int $days, array $attributes = []): Service
    {
        $service = Service::factory()->for($this->client('Raz', 'raz'.$days.uniqid().'@example.test'))->create($attributes + [
            'next_due_date' => today()->subDays($days),
        ]);

        $invoice = Invoice::factory()->create(['client_id' => $service->client_id, 'due_at' => today()->subDays($days), 'total' => 1000]);
        $invoice->items()->create(['type' => 'service', 'service_id' => $service->id, 'description' => 'Renewal', 'amount' => 1000, 'period_start' => today()->subDays($days)]);

        return $service;
    }
}
