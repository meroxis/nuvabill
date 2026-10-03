<?php

namespace Tests\Feature\Gateways;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\PaymentIntent;
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

    public function test_the_update_marks_open_links_as_test_links_while_wayl_is_in_test_mode(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'test']);
        [, $invoice] = $this->invoice();
        $open = $this->olderIntent($invoice, 'NB-1-open', PaymentIntent::STATUS_PENDING);
        $paid = $this->olderIntent($invoice, 'NB-1-paid', PaymentIntent::STATUS_PAID);

        $this->runMigration();

        $this->assertSame('test', $open->fresh()->meta['env']);
        $this->assertSame(30000, $open->fresh()->meta['charged_amount']);
        $this->assertArrayNotHasKey('env', $paid->fresh()->meta);

        // Once Wayl is live, the old test link no longer pays the invoice.
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);
        $this->postWebhook('NB-1-open')->assertOk();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
    }

    public function test_the_update_leaves_open_links_alone_while_wayl_is_live(): void
    {
        $this->enableGateway('wayl', ['api_token' => 'wayl-token', 'mode' => 'live']);
        [, $invoice] = $this->invoice();
        $open = $this->olderIntent($invoice, 'NB-1-open', PaymentIntent::STATUS_PENDING);

        $this->runMigration();

        $this->assertArrayNotHasKey('env', $open->fresh()->meta);
        $this->postWebhook('NB-1-open')->assertOk();
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
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
