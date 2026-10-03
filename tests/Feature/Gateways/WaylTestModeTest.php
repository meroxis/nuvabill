<?php

namespace Tests\Feature\Gateways;

use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\PaymentIntent;
use App\Models\PushSubscription;
use App\Push\WebPush;
use App\Support\AttentionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Wayl uses the same address and token in Test and Live mode, so the mode a link was made in is
 * kept with it: a test link never pays an invoice once Wayl is live.
 */
class WaylTestModeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.thewayl.com/api/v1/links' => Http::response(['data' => ['id' => 'cmlink_1', 'code' => 'I94F590I', 'url' => 'https://checkout.thewayl.com/pay/I94F590I']], 201),
            'api.thewayl.com/api/v1/links/*' => Http::response(['data' => ['id' => 'cmlink_1', 'total' => '30000', 'status' => 'Complete']]),
        ]);
    }

    public function test_wayl_test_links_do_not_count_after_switching_to_live(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);
        [$client, $invoice] = $this->invoice();

        $this->actingAs($client, 'web')->post(route('client.invoices.pay', $invoice), ['gateway' => 'wayl'])
            ->assertRedirect('https://checkout.thewayl.com/pay/I94F590I');
        $intent = PaymentIntent::query()->sole();
        $this->assertSame('test', $intent->meta['env']);

        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);

        // The link is later paid with a Wayl test card: the signed webhook and the return page both arrive.
        $this->postWebhook($intent->reference)->assertOk();
        $this->get(route('client.invoices.return', [$invoice, 'wayl']))->assertRedirect(route('client.invoices.show', $invoice));

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(PaymentIntent::STATUS_FAILED, $intent->fresh()->status);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'GET');
    }

    public function test_live_links_still_count_while_staff_try_test_mode(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);
        [$client, $invoice] = $this->invoice();

        $this->actingAs($client, 'web')->post(route('client.invoices.pay', $invoice), ['gateway' => 'wayl']);
        $intent = PaymentIntent::query()->sole();
        $this->assertSame('live', $intent->meta['env']);

        // Real money paid on a live link while staff try Test mode for a moment.
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);
        $this->postWebhook($intent->reference)->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_PAID, $intent->fresh()->status);
    }

    public function test_the_update_marks_open_links_made_in_test_mode_as_test_links(): void
    {
        [, $invoice] = $this->invoice();
        $older = $this->olderIntent($invoice, 'NB-1-older', PaymentIntent::STATUS_PENDING);
        $this->travel(1)->minutes();
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);
        $this->travel(1)->minutes();
        $open = $this->olderIntent($invoice, 'NB-1-open', PaymentIntent::STATUS_PENDING);
        $paid = $this->olderIntent($invoice, 'NB-1-paid', PaymentIntent::STATUS_PAID);

        $this->runMigration();

        // Made after Wayl was set to Test mode, so made in Test mode.
        $this->assertSame('test', $open->fresh()->meta['env']);
        $this->assertSame(30000, $open->fresh()->meta['charged_amount']);
        $this->assertArrayNotHasKey('env', $paid->fresh()->meta);
        // Made before the mode was set, so it may be a live link.
        $this->assertSame('unknown', $older->fresh()->meta['env']);

        // Once Wayl is live, the old test link no longer pays the invoice.
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);
        $this->postWebhook('NB-1-open')->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(PaymentIntent::STATUS_FAILED, $open->fresh()->status);
    }

    public function test_the_update_keeps_open_links_made_while_live_working(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);
        [, $invoice] = $this->invoice();
        $this->travel(1)->minutes();
        $open = $this->olderIntent($invoice, 'NB-1-open', PaymentIntent::STATUS_PENDING);

        $this->runMigration();

        $this->assertSame('live', $open->fresh()->meta['env']);
        $this->postWebhook('NB-1-open')->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertFalse(ActivityLog::query()->where('action', 'payment.review')->exists());
    }

    public function test_older_open_links_go_to_staff_instead_of_paying_the_invoice_when_wayl_is_live(): void
    {
        [$client, $invoice, $open] = $this->linkFromTheTestWeekAfterTheUpdate();

        $this->assertSame('unknown', $open->fresh()->meta['env']);

        // The link is paid on Wayl's test page with a test card: the return page and the signed webhook arrive.
        $this->actingAs($client, 'web')->get(route('client.invoices.return', [$invoice, 'wayl']))->assertRedirect(route('client.invoices.show', $invoice));
        $this->postWebhook('NB-1-open')->assertOk();
        $this->postWebhook('NB-1-open')->assertOk();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
        $this->assertSame(0, CreditTransaction::query()->count());
        $this->assertSame(PaymentIntent::STATUS_FAILED, $open->fresh()->status);

        // Staff are told once, on the invoice and the client.
        $entry = ActivityLog::query()->where('action', 'payment.review')->sole();
        $this->assertSame([$invoice->getMorphClass(), $invoice->id, $client->id], [$entry->subject_type, $entry->subject_id, $entry->client_id]);
        $this->assertStringContainsString('NB-1-open', $entry->description);
        $this->assertStringContainsString('add the payment by hand', $entry->description);
    }

    public function test_a_held_payment_alerts_billing_staff_and_stays_on_their_dashboard_until_the_invoice_is_paid(): void
    {
        Http::fake(['fcm.googleapis.com/*' => Http::response('', 201)]);
        $billing = Admin::factory()->withPermissions(['clients.view', 'billing.view', 'billing.manage'])->create(['name' => 'Mer Las']);
        $support = Admin::factory()->withPermissions(['clients.view', 'support.manage'])->create(['name' => 'Raz']);
        $this->subscribe($billing, 'billing');
        $this->subscribe($support, 'support');
        [, $invoice] = $this->linkFromTheTestWeekAfterTheUpdate();

        // Paid on the old link: the webhook arrives twice, the phones of billing staff ring once.
        $this->postWebhook('NB-1-open')->assertOk();
        $this->postWebhook('NB-1-open')->assertOk();

        $phone = fn (string $name): int => Http::recorded(fn (Request $request): bool => $request->url() === 'https://fcm.googleapis.com/fcm/send/'.$name)->count();
        $this->assertSame(1, $phone('billing'));
        $this->assertSame(0, $phone('support'));
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        $this->signInAdmin($support);
        $this->assertNotContains('payments.review', array_column(AttentionList::items(), 'key'));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('may be a test payment');

        $this->signInAdmin($billing);
        $item = collect(AttentionList::items())->firstWhere('key', 'payments.review');
        $this->assertSame(['crit', route('admin.invoices.show', $invoice)], [$item['tone'], $item['url']]);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('1 payment was not counted because it may be a test payment.');

        // Staff find the money in Wayl and add the payment by hand.
        app(PaymentRecorder::class)->record($invoice->fresh(), 3000000, 'banktransfer', 'wayl-checked-by-staff');

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertNotContains('payments.review', array_column(AttentionList::items(), 'key'));
    }

    /**
     * Wayl ran in Test mode for the first week, then went live, and then the update came. The
     * client still has an open link from the test week.
     *
     * @return array{0: Client, 1: Invoice, 2: PaymentIntent}
     */
    private function linkFromTheTestWeekAfterTheUpdate(): array
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);
        [$client, $invoice] = $this->invoice();
        $this->travel(1)->minutes();
        $open = $this->olderIntent($invoice, 'NB-1-open', PaymentIntent::STATUS_PENDING);
        $this->travel(7)->days();
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);

        $this->runMigration();

        return [$client, $invoice, $open];
    }

    private function subscribe(Admin $admin, string $name): void
    {
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $key = openssl_pkey_new($options) ?: openssl_pkey_new($options + ['config' => resource_path('openssl.cnf')]);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.$name;

        PushSubscription::query()->create([
            'admin_id' => $admin->id,
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hashOf($endpoint),
            'public_key' => WebPush::encode(WebPush::rawPublicKey($key)),
            'auth_token' => WebPush::encode(random_bytes(16)),
        ]);
    }

    /**
     * A link made before Wayl links kept their mode.
     */
    private function olderIntent(Invoice $invoice, string $reference, string $status): PaymentIntent
    {
        return PaymentIntent::query()->create([
            'invoice_id' => $invoice->id, 'gateway' => 'wayl', 'reference' => $reference, 'amount' => 3000000, 'currency' => 'IQD', 'status' => $status,
            'meta' => ['link_id' => 'cmlink_1', 'code' => 'I94F590I', 'charged_amount' => 30000, 'charged_currency' => 'IQD', 'rate' => null],
        ]);
    }

    private function runMigration(): void
    {
        (require database_path('migrations/2027_07_02_000012_gateways_mark_wayl_test_links.php'))->up();
    }

    private function postWebhook(string $reference): TestResponse
    {
        $payload = (string) json_encode(['event' => 'order.completed', 'referenceId' => $reference, 'paymentStatus' => 'Complete']);
        $secret = hash_hmac('sha256', 'nuvabill-wayl-webhook', (string) config('app.key'));

        return $this->call('POST', route('webhooks.gateway', 'wayl'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_WAYL_SIGNATURE_256' => hash_hmac('sha256', $payload, $secret)], $payload);
    }

    /**
     * @return array{0: Client, 1: Invoice}
     */
    private function invoice(): array
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'IQD']);

        return [$client, Invoice::factory()->create(['client_id' => $client->id, 'currency' => 'IQD', 'total' => 3000000])];
    }
}
