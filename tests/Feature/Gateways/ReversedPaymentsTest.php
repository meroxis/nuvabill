<?php

namespace Tests\Feature\Gateways;

use App\Billing\Affiliates;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\PlanChanges;
use App\Billing\RefundIssuer;
use App\Contracts\PaymentGateway;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Gateways\PaymentReversal;
use App\Extensions\Gateways\RefundResult;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\Transaction;
use App\Support\AttentionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * Money that goes back at the payment service (a refund made in Stripe or PayPal, or a dispute)
 * is recorded once on the invoice and staff are told. The invoice, its services and the wallet
 * stay as they are.
 */
class ReversedPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_test_secret';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => self::STRIPE_SECRET]);
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'client-id', 'client_secret' => 'client-secret', 'webhook_id' => 'WH-1']);
        $this->setSettings(['company.email' => 'billing@example.test', 'billing.currency' => 'USD']);
    }

    public function test_a_refund_made_in_stripe_is_recorded_once_and_staff_are_told(): void
    {
        $invoice = $this->paidInvoice(5000, 'stripe', 'pi_test_1');
        $payment = $invoice->transactions()->sole();

        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 2000))->assertOk();
        // Stripe sends a notice again when it got no answer in time.
        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 2000))->assertOk();

        $refund = $invoice->transactions()->where('type', 'refund')->sole();
        $this->assertSame([-2000, 'stripe', 'ch_1-refunded-2000', 'USD'], [$refund->amount, $refund->gateway, $refund->reference, $refund->currency]);
        $this->assertSame(['refund_of' => $payment->id, 'through_gateway' => true, 'reported_by_gateway' => true, 'kind' => PaymentReversal::REFUND], $refund->meta);

        // Nothing else changes by itself.
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->client->fresh()->credit);

        $log = ActivityLog::query()->where('action', ActivityLog::PAYMENT_REVERSED)->sole();
        $this->assertSame([$invoice->id, $invoice->client_id], [$log->subject_id, $log->client_id]);
        $this->assertStringContainsString('Stripe says $20.00 of payment pi_test_1 on invoice '.$invoice->number.' was refunded there.', $log->description);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('billing@example.test')
            && $mail->subjectLine === "A payment for invoice {$invoice->number} was refunded at Stripe");

        $this->signInAdmin();
        $item = fn (): ?array => collect(AttentionList::items())->firstWhere('key', 'payments.reversed');
        $this->assertStringStartsWith('1 payment was refunded or disputed at the payment service.', $item()['text']);
        $this->assertSame(route('admin.invoices.show', $invoice), $item()['url']);

        // Staff finish it with a credit note. The money already went back, so none is sent.
        Http::fake();
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '20.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('status');
        Http::assertNothingSent();
        $this->assertSame(-2000, (int) $invoice->transactions()->where('type', 'refund')->sum('amount'));
        $this->assertNull($item());

        // A second refund in Stripe: only the new part is recorded.
        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 5000))->assertOk();
        $this->assertSame([-2000, -3000], $invoice->transactions()->where('type', 'refund')->orderBy('id')->pluck('amount')->all());
    }

    public function test_a_refund_sent_from_here_is_not_recorded_again_by_the_notice_from_stripe(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'amount' => 1399, 'status' => 'succeeded'])]);
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');
        $this->signInAdmin();
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 1399, 1399))->assertOk();

        $this->assertSame(['re_1'], $invoice->transactions()->where('type', 'refund')->pluck('reference')->all());
        $this->assertSame(0, ActivityLog::query()->where('action', ActivityLog::PAYMENT_REVERSED)->count());
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'was refunded at'));
    }

    public function test_a_notice_about_a_refund_that_is_still_being_sent_from_here_is_not_recorded(): void
    {
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');
        // The try is saved on the payment before it is sent; its answer has not come back yet.
        $invoice->transactions()->sole()->forceFill(['meta' => ['refund_pending' => ['key' => 'payment-1-refund-1-500-abc', 'amount' => 500, 'gateway' => 'stripe', 'since' => now()->toIso8601String()]]])->save();

        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 1399, 500))->assertOk();

        $this->assertSame(0, $invoice->transactions()->where('type', 'refund')->count());
    }

    public function test_a_refund_staff_wrote_down_first_is_not_recorded_again(): void
    {
        $invoice = $this->paidInvoice(1399, 'stripe', 'pi_test_1');
        $this->signInAdmin();
        // Staff record the refund without sending it, then make it in Stripe.
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '0'])->assertSessionHas('status');

        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 1399, 1399))->assertOk();

        $this->assertSame([-1399], $invoice->transactions()->where('type', 'refund')->pluck('amount')->all());
    }

    public function test_a_dispute_in_stripe_is_recorded_and_money_that_comes_back_too(): void
    {
        $this->setSettings(['affiliates.enabled' => true, 'affiliates.recurring' => true, 'affiliates.percent' => 10]);
        $affiliate = app(Affiliates::class)->join(Client::factory()->create(['first_name' => 'Mer', 'last_name' => '']));
        $raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']);
        AffiliateReferral::query()->create(['affiliate_id' => $affiliate->id, 'client_id' => $raz->id]);
        $invoice = $this->paidInvoice(5000, 'stripe', 'pi_test_1', $raz);
        $this->assertSame(AffiliateCommission::STATUS_PENDING, AffiliateCommission::query()->sole()->status);

        // A question from the bank takes no money yet.
        $this->postStripe('charge.dispute.created', $this->dispute('pi_test_1', 5000, 'warning_needs_response'))->assertOk();
        $this->assertSame(0, $invoice->transactions()->where('type', 'refund')->count());

        $this->postStripe('charge.dispute.created', $this->dispute('pi_test_1', 5000, 'needs_response'))->assertOk();
        $this->postStripe('charge.dispute.funds_withdrawn', $this->dispute('pi_test_1', 5000, 'needs_response'))->assertOk();

        $line = $invoice->transactions()->where('type', 'refund')->sole();
        $this->assertSame([-5000, 'dp_1', PaymentReversal::CHARGEBACK], [$line->amount, $line->reference, $line->meta['kind']]);
        $this->assertSame(AffiliateCommission::STATUS_CANCELLED, AffiliateCommission::query()->sole()->status);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status, 'Staff decide what happens to the invoice and its services.');
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('billing@example.test')
            && $mail->subjectLine === "A payment for invoice {$invoice->number} was disputed at Stripe");

        $this->signInAdmin();
        $this->assertNotNull(collect(AttentionList::items())->firstWhere('key', 'payments.reversed'));
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('Dispute');

        // The dispute ends well: the money is back, once.
        $this->postStripe('charge.dispute.funds_reinstated', $this->dispute('pi_test_1', 5000, 'won'))->assertOk();
        $this->postStripe('charge.dispute.funds_reinstated', $this->dispute('pi_test_1', 5000, 'won'))->assertOk();

        $this->assertSame([-5000, 5000], $invoice->transactions()->where('type', 'refund')->orderBy('id')->pluck('amount')->all());
        $this->assertSame(5000, (int) Transaction::query()->revenue()->where('invoice_id', $invoice->id)->sum('amount'));
        $this->assertNull(collect(AttentionList::items())->firstWhere('key', 'payments.reversed'));
        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('Dispute won');
    }

    public function test_a_part_that_went_back_cuts_the_commission_when_it_is_released(): void
    {
        $this->setSettings(['affiliates.enabled' => true, 'affiliates.recurring' => true, 'affiliates.percent' => 10, 'affiliates.hold_days' => 30]);
        $affiliate = app(Affiliates::class)->join(Client::factory()->create(['first_name' => 'Mer', 'last_name' => '']));
        $raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '']);
        AffiliateReferral::query()->create(['affiliate_id' => $affiliate->id, 'client_id' => $raz->id]);
        $invoice = $this->paidInvoice(5000, 'stripe', 'pi_test_1', $raz);

        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 2000))->assertOk();
        $this->assertSame([500, AffiliateCommission::STATUS_PENDING], [AffiliateCommission::query()->sole()->amount, AffiliateCommission::query()->sole()->status]);

        $this->travel(31)->days();
        app(Affiliates::class)->release();

        $this->assertSame([300, AffiliateCommission::STATUS_AVAILABLE], [AffiliateCommission::query()->sole()->amount, AffiliateCommission::query()->sole()->status]);
    }

    public function test_notices_about_payments_this_site_did_not_record_change_nothing(): void
    {
        $invoice = $this->paidInvoice(5000, 'stripe', 'pi_test_1');

        // Another site on the same Stripe account, and the same ID in other letter case.
        $this->postStripe('charge.refunded', $this->charge('pi_other_site', 5000, 5000))->assertOk();
        $this->postStripe('charge.refunded', $this->charge('pi_TEST_1', 5000, 5000))->assertOk();
        $this->postStripe('charge.dispute.created', $this->dispute('pi_other_site', 5000, 'needs_response'))->assertOk();
        // A notice nobody signed.
        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 5000), 'whsec_wrong')->assertStatus(400);
        // Another currency than the payment.
        $this->postStripe('charge.refunded', ['currency' => 'eur'] + $this->charge('pi_test_1', 5000, 5000))->assertOk();

        $this->assertSame(1, $invoice->transactions()->count());
        $this->assertSame(0, ActivityLog::query()->where('action', ActivityLog::PAYMENT_REVERSED)->count());
    }

    public function test_a_payment_never_goes_back_more_than_once(): void
    {
        $invoice = $this->paidInvoice(5000, 'stripe', 'pi_test_1');

        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 3000))->assertOk();
        $this->postStripe('charge.dispute.created', $this->dispute('pi_test_1', 5000, 'needs_response'))->assertOk();

        $this->assertSame([-3000, -2000], $invoice->transactions()->where('type', 'refund')->orderBy('id')->pluck('amount')->all());
        $this->assertSame(0, (int) Transaction::query()->revenue()->where('invoice_id', $invoice->id)->sum('amount'));
    }

    public function test_a_refund_made_in_paypal_and_a_payment_paypal_took_back_are_recorded_once(): void
    {
        $this->fakeVerifiedPayPalWebhooks();
        $invoice = $this->paidInvoice(899, 'paypal', 'CAP1');

        $this->postPayPal('PAYMENT.CAPTURE.REFUNDED', $this->payPalResource('REF1', 'CAP1', '3.00', '3.00'))->assertOk();
        $this->postPayPal('PAYMENT.CAPTURE.REFUNDED', $this->payPalResource('REF1', 'CAP1', '3.00', '3.00'))->assertOk();
        $this->postPayPal('PAYMENT.CAPTURE.REVERSED', $this->payPalResource('REV1', 'CAP1', '5.99'))->assertOk();
        // A capture of another site on the same PayPal app, and a refund PayPal could not make.
        $this->postPayPal('PAYMENT.CAPTURE.REFUNDED', $this->payPalResource('REF2', 'CAP9', '1.00'))->assertOk();
        $this->postPayPal('PAYMENT.CAPTURE.REFUNDED', ['status' => 'FAILED'] + $this->payPalResource('REF3', 'CAP1', '1.00'))->assertOk();

        $lines = $invoice->transactions()->where('type', 'refund')->orderBy('id')->get();
        $this->assertSame([[-300, 'REF1', PaymentReversal::REFUND], [-599, 'REV1', PaymentReversal::CHARGEBACK]], $lines->map(fn (Transaction $line): array => [$line->amount, $line->reference, $line->meta['kind']])->all());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === "A payment for invoice {$invoice->number} was refunded at PayPal");
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === "A payment for invoice {$invoice->number} was disputed at PayPal");
    }

    public function test_a_paypal_refund_sent_from_here_is_not_recorded_again(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
            'api-m.sandbox.paypal.com/v2/payments/captures/CAP1/refund' => Http::response(['id' => 'REF1', 'status' => 'COMPLETED']),
        ]);
        $invoice = $this->paidInvoice(899, 'paypal', 'CAP1');
        $this->signInAdmin();
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $this->postPayPal('PAYMENT.CAPTURE.REFUNDED', $this->payPalResource('REF1', 'CAP1', '8.99'))->assertOk();

        $this->assertSame(['REF1'], $invoice->transactions()->where('type', 'refund')->pluck('reference')->all());
    }

    public function test_a_paypal_notice_that_is_not_verified_records_nothing(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'FAILURE']),
        ]);
        $invoice = $this->paidInvoice(899, 'paypal', 'CAP1');

        $this->postPayPal('PAYMENT.CAPTURE.REVERSED', $this->payPalResource('REV1', 'CAP1', '8.99'))->assertStatus(400);

        $this->assertSame(1, $invoice->transactions()->count());
    }

    public function test_a_notice_that_arrives_before_the_answer_of_a_refund_is_that_refund(): void
    {
        // A gateway without refund keys: its notice can arrive while the refund call is still running.
        $gateway = $this->mock(PaymentGateway::class, function (MockInterface $mock): void {
            $mock->shouldReceive('supportsRefunds')->andReturn(true);
            $mock->shouldReceive('isConfigured')->andReturn(true);
            $mock->shouldReceive('refund')->once()->andReturnUsing(function (): RefundResult {
                app(RefundIssuer::class)->recordReversal(PaymentReversal::refund('legacy-pay-1', 1000, 'USD', 'legacy-refund-1'), 'legacy');

                return new RefundResult(1000, 'legacy-refund-1');
            });
        });
        $this->partialMock(ExtensionManager::class, fn (MockInterface $mock) => $mock->shouldReceive('gateway')->with('legacy')->andReturn($gateway));
        $invoice = $this->paidInvoice(1000, 'legacy', 'legacy-pay-1');
        $this->signInAdmin();

        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHas('status');

        $this->assertSame([-1000], $invoice->transactions()->where('type', 'refund')->pluck('amount')->all());
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
    }

    public function test_unused_time_is_not_credited_for_money_that_went_back(): void
    {
        $this->travelTo('2026-10-01 10:00');
        $this->setSettings(['wallet.enabled' => true]);
        $starter = Product::factory()->priced(1000)->create(['name' => 'Starter']);
        $business = Product::factory()->priced(2000)->create(['name' => 'Business']);
        $business->update(['upgrade_product_ids' => [$starter->id]]);
        $client = Client::factory()->create(['first_name' => 'Las', 'last_name' => '', 'currency' => 'USD']);
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $business->id, 'recurring_amount' => 2000, 'next_due_date' => '2026-10-16']);
        $invoice = Invoice::factory()->for($client)->create(['total' => 2000, 'subtotal' => 2000]);
        $invoice->items()->create(['type' => InvoiceItem::TYPE_SERVICE, 'service_id' => $service->id, 'description' => 'Business', 'amount' => 2000, 'period_start' => '2026-09-16', 'period_end' => '2026-10-15']);
        app(PaymentRecorder::class)->record($invoice, 2000, 'stripe', 'pi_test_1');
        $this->assertSame(1000, app(PlanChanges::class)->quote($service->fresh(), $starter)['credit'], 'Half of the paid period is left.');

        $this->postStripe('charge.dispute.created', $this->dispute('pi_test_1', 2000, 'needs_response'))->assertOk();

        $this->assertSame(0, app(PlanChanges::class)->quote($service->fresh(), $starter)['credit']);
        $this->actingAs($client, 'web')->post(route('client.services.change-plan.store', $service), ['product_id' => $starter->id]);
        $this->assertSame(0, $client->fresh()->credit);
    }

    public function test_money_that_went_back_cannot_go_into_the_wallet_as_well(): void
    {
        $this->setSettings(['wallet.enabled' => true]);
        $invoice = $this->paidInvoice(5000, 'stripe', 'pi_test_1');
        $this->postStripe('charge.refunded', $this->charge('pi_test_1', 5000, 2000))->assertOk();
        $this->signInAdmin();

        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '50.00', 'method' => 'wallet'])
            ->assertSessionHasErrors(['amount' => 'Part of this invoice already went back to the client. At most $30.00 can go into the wallet.']);
        $this->assertSame(0, $invoice->client->fresh()->credit);
        $this->assertSame(0, $invoice->creditNotes()->count());

        // The part the client still pays for can go into the wallet.
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '30.00', 'method' => 'wallet'])->assertSessionHas('status');
        $this->assertSame(3000, $invoice->client->fresh()->credit);

        // The part that went back is finished with a credit note that sends nothing.
        Http::fake();
        $this->post(route('admin.invoices.credit-notes.store', $invoice), ['amount' => '20.00', 'method' => 'refund', 'through_gateway' => '1'])->assertSessionHas('status');
        Http::assertNothingSent();
        $this->assertSame(InvoiceStatus::Refunded, $invoice->fresh()->status);
        $this->assertSame(3000, $invoice->client->fresh()->credit);
    }

    public function test_cancelling_a_part_paid_invoice_does_not_put_money_that_went_back_into_the_wallet(): void
    {
        $this->setSettings(['wallet.enabled' => true]);
        $client = Client::factory()->create(['first_name' => 'Las', 'last_name' => '', 'currency' => 'USD']);
        $invoice = Invoice::factory()->for($client)->create(['total' => 5000, 'subtotal' => 5000]);
        app(PaymentRecorder::class)->record($invoice, 2000, 'stripe', 'pi_test_1');
        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer', 'bank-1');
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        $this->postStripe('charge.dispute.created', $this->dispute('pi_test_1', 2000, 'needs_response'))->assertOk();
        app(InvoiceManager::class)->cancel($invoice);

        $this->assertSame(InvoiceStatus::Cancelled, $invoice->fresh()->status);
        $this->assertSame(1000, $client->fresh()->credit, 'Only the bank transfer is still here to give back.');
    }

    public function test_a_dispute_after_the_money_went_into_the_wallet_is_shown_to_staff(): void
    {
        $this->setSettings(['wallet.enabled' => true]);
        $client = Client::factory()->create(['first_name' => 'Las', 'last_name' => '', 'currency' => 'USD']);
        $invoice = Invoice::factory()->for($client)->create(['total' => 5000, 'subtotal' => 5000]);
        app(PaymentRecorder::class)->record($invoice, 2000, 'stripe', 'pi_test_1');
        app(InvoiceManager::class)->cancel($invoice);
        $this->assertSame(2000, $client->fresh()->credit);

        $this->postStripe('charge.dispute.created', $this->dispute('pi_test_1', 2000, 'needs_response'))->assertOk();

        $this->assertSame(-2000, (int) $invoice->transactions()->where('type', 'refund')->where('gateway', 'stripe')->sum('amount'));
        $this->assertSame(2000, $client->fresh()->credit, 'Staff decide what happens to the wallet.');
        $this->signInAdmin();
        $items = collect(AttentionList::items());
        $this->assertNull($items->firstWhere('key', 'payments.reversed'));
        $item = $items->firstWhere('key', 'payments.reversed_after_return');
        $this->assertStringStartsWith('1 payment went back at the payment service after its money was already given back here.', $item['text']);
        $this->assertSame(route('admin.invoices.show', $invoice), $item['url']);
    }

    public function test_the_setup_texts_name_the_events_for_refunds_and_disputes(): void
    {
        $stripe = app(ExtensionManager::class)->gateway('stripe')->settingsFields()['webhook_secret']['help'];
        $payPal = app(ExtensionManager::class)->gateway('paypal')->settingsFields()['webhook_id']['help'];

        foreach (['charge.refunded', 'charge.dispute.created', 'charge.dispute.funds_withdrawn', 'charge.dispute.funds_reinstated'] as $event) {
            $this->assertStringContainsString($event, $stripe);
        }

        foreach (['PAYMENT.CAPTURE.REFUNDED', 'PAYMENT.CAPTURE.REVERSED'] as $event) {
            $this->assertStringContainsString($event, $payPal);
        }

        // A saved secret hides its help, so the settings page lists the events under the webhook URL.
        $this->signInAdmin();
        $this->get(route('admin.settings.gateways.edit', 'stripe'))->assertOk()
            ->assertSee('Events to send to this URL:')
            ->assertSee('checkout.session.completed, checkout.session.async_payment_succeeded, payment_intent.succeeded, charge.refunded, charge.dispute.created, charge.dispute.funds_withdrawn, charge.dispute.funds_reinstated');
        $this->get(route('admin.settings.gateways.edit', 'paypal'))->assertOk()
            ->assertSee('PAYMENT.CAPTURE.COMPLETED, PAYMENT.CAPTURE.REFUNDED, PAYMENT.CAPTURE.REVERSED');
        $this->get(route('admin.settings.gateways.edit', 'wayl'))->assertOk()->assertDontSee('Events to send to this URL:');
    }

    private function paidInvoice(int $total, string $gateway, string $reference, ?Client $client = null): Invoice
    {
        $invoice = Invoice::factory()->for($client ?? Client::factory()->create(['first_name' => 'Las', 'last_name' => '', 'currency' => 'USD']))->create(['total' => $total, 'subtotal' => $total]);
        app(PaymentRecorder::class)->record($invoice, $total, $gateway, $reference);

        return $invoice->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(string $paymentIntent, int $amount, int $refunded): array
    {
        return ['id' => 'ch_1', 'object' => 'charge', 'amount' => $amount, 'amount_refunded' => $refunded, 'currency' => 'usd', 'payment_intent' => $paymentIntent, 'refunded' => $refunded >= $amount];
    }

    /**
     * @return array<string, mixed>
     */
    private function dispute(string $paymentIntent, int $amount, string $status): array
    {
        return ['id' => 'dp_1', 'object' => 'dispute', 'amount' => $amount, 'currency' => 'usd', 'charge' => 'ch_1', 'payment_intent' => $paymentIntent, 'reason' => 'general', 'status' => $status];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    private function postStripe(string $type, array $object, string $secret = self::STRIPE_SECRET): TestResponse
    {
        $payload = (string) json_encode(['id' => 'evt_1', 'type' => $type, 'data' => ['object' => $object]]);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return $this->call('POST', route('webhooks.gateway', 'stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function fakeVerifiedPayPalWebhooks(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token-1']),
            'api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature' => Http::response(['verification_status' => 'SUCCESS']),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payPalResource(string $id, string $capture, string $value, ?string $refundedSoFar = null): array
    {
        return [
            'id' => $id,
            'status' => 'COMPLETED',
            'amount' => ['currency_code' => 'USD', 'value' => $value],
            'links' => [
                ['rel' => 'self', 'href' => 'https://api.sandbox.paypal.com/v2/payments/refunds/'.$id],
                ['rel' => 'up', 'href' => 'https://api.sandbox.paypal.com/v2/payments/captures/'.$capture],
            ],
        ] + ($refundedSoFar === null ? [] : ['seller_payable_breakdown' => ['total_refunded_amount' => ['currency_code' => 'USD', 'value' => $refundedSoFar]]]);
    }

    /**
     * @param  array<string, mixed>  $resource
     */
    private function postPayPal(string $type, array $resource): TestResponse
    {
        return $this->postJson(route('webhooks.gateway', 'paypal'), ['event_type' => $type, 'resource' => $resource], [
            'PAYPAL-AUTH-ALGO' => 'SHA256withRSA',
            'PAYPAL-CERT-URL' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-1',
            'PAYPAL-TRANSMISSION-ID' => 'T-1',
            'PAYPAL-TRANSMISSION-SIG' => 'sig',
            'PAYPAL-TRANSMISSION-TIME' => '2026-10-03T10:00:00Z',
        ]);
    }
}
