<?php

namespace Tests\Feature;

use App\Automation\DailyAutomation;
use App\Billing\InvoiceManager;
use App\Enums\InvoiceStatus;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AutoPayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-12 00:15:00');
        Mail::fake();
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
    }

    public function test_a_client_saves_the_card_while_paying_and_it_becomes_the_default(): void
    {
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_raz']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_1', 'url' => 'https://checkout.stripe.com/c/pay/cs_1']),
            'api.stripe.com/v1/checkout/sessions/cs_1*' => Http::response([
                'id' => 'cs_1', 'payment_status' => 'paid', 'amount_total' => 1299, 'currency' => 'usd', 'customer' => 'cus_raz',
                // The marker this site puts on its own Stripe sessions, made from the app key.
                'metadata' => ['invoice_id' => '1', 'save' => '1', 'nuvabill_site' => substr(hash_hmac('sha256', 'nuvabill-stripe-checkout', (string) config('app.key')), 0, 32)],
                'payment_intent' => ['id' => 'pi_1', 'payment_method' => ['id' => 'pm_visa', 'type' => 'card', 'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 8, 'exp_year' => 2028]]],
            ]),
        ]);
        $client = $this->client();
        $invoice = $this->renewalInvoice($client);
        $this->assertSame(1, $invoice->id);

        $this->actingAs($client, 'web')->get(route('client.invoices.show', $invoice))->assertOk()->assertSee('Save it and pay my renewals automatically');
        $this->post(route('client.invoices.pay', $invoice), ['gateway' => 'stripe', 'save_method' => '1'])->assertRedirect('https://checkout.stripe.com/c/pay/cs_1');

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions'
            && $request['customer'] === 'cus_raz'
            && $request['payment_intent_data']['setup_future_usage'] === 'off_session'
            && $request['metadata']['save'] === '1');

        $this->get(route('client.invoices.return', [$invoice, 'stripe']).'?session_id=cs_1')->assertRedirect(route('client.invoices.show', $invoice));

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $method = PaymentMethod::query()->sole();
        $this->assertSame(['client_id' => $client->id, 'reference' => 'pm_visa', 'customer_reference' => 'cus_raz', 'is_default' => true], $method->only('client_id', 'reference', 'customer_reference', 'is_default'));
        $this->assertSame('Visa •••• 4242', $method->label());
        $this->assertSame('08/2028', $method->expiry());
    }

    public function test_paying_without_ticking_save_it_keeps_nothing(): void
    {
        Http::fake(['api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_2', 'url' => 'https://checkout.stripe.com/c/pay/cs_2'])]);
        $client = $this->client();

        $this->actingAs($client, 'web')->post(route('client.invoices.pay', $this->renewalInvoice($client)), ['gateway' => 'stripe']);

        Http::assertSent(fn (Request $request): bool => ! isset($request['payment_intent_data']['setup_future_usage']) && ! isset($request['customer']));
    }

    public function test_a_client_adds_a_card_without_paying(): void
    {
        Http::fake([
            'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_raz']),
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_setup', 'url' => 'https://checkout.stripe.com/c/setup/cs_setup']),
            'api.stripe.com/v1/checkout/sessions/cs_setup*' => Http::response([
                'id' => 'cs_setup', 'customer' => 'cus_raz', 'metadata' => ['client_id' => '1'],
                'setup_intent' => ['status' => 'succeeded', 'payment_method' => ['id' => 'pm_master', 'type' => 'card', 'card' => ['brand' => 'mastercard', 'last4' => '5100', 'exp_month' => 1, 'exp_year' => 2029]]],
            ]),
        ]);
        $client = $this->client();
        $this->assertSame(1, $client->id);

        $this->actingAs($client, 'web')->get(route('client.account.payment-methods'))->assertOk()->assertSee('Add a card');
        $this->post(route('client.account.payment-methods.store', 'stripe'))->assertRedirect('https://checkout.stripe.com/c/setup/cs_setup');
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/checkout/sessions' && $request['mode'] === 'setup');

        $this->get(route('client.account.payment-methods.return', 'stripe').'?session_id=cs_setup')->assertSessionHas('status');

        $this->assertSame('Mastercard •••• 5100', PaymentMethod::query()->sole()->label());
    }

    public function test_the_nightly_run_charges_a_due_renewal_to_the_saved_card(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_auto', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd'])]);
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        $summary = app(DailyAutomation::class)->run();

        $this->assertSame(1, $summary['charged']);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame('pi_auto', $invoice->transactions()->value('reference'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.stripe.com/v1/payment_intents'
            && $request['off_session'] === 'true' && $request['confirm'] === 'true'
            && $request['customer'] === 'cus_raz' && $request['payment_method'] === 'pm_visa'
            && (int) $request['amount'] === 1299
            && $request->header('Idempotency-Key')[0] === 'nuvabill-invoice-'.$invoice->id.'-1299-try-0');
    }

    public function test_wallet_credit_is_used_first(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_rest', 'status' => 'succeeded', 'amount_received' => 799, 'currency' => 'usd'])]);
        $client = $this->client(['credit' => 500]);
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();

        Http::assertSent(fn (Request $request): bool => (int) $request['amount'] === 799);
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(0, $client->fresh()->credit);
    }

    public function test_a_declined_card_is_tried_again_after_3_and_7_days_then_left_to_the_client(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['code' => 'card_declined', 'message' => 'Your card has insufficient funds.']], 402)]);
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(1, $invoice->autopay_attempts);
        $this->assertSame('2026-10-15', $invoice->autopay_retry_at->toDateString());
        $this->assertSame('Your card has insufficient funds.', $invoice->autopay_error);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($client->email)
            && $mail->subjectLine === 'We could not charge your Visa •••• 4242 for invoice '.$invoice->displayNumber()
            && str_contains($mail->bodyHtml, 'We will try again on 15 Oct 2026'));

        // Nothing happens before the next try; no overdue reminder while a try is still to come.
        Carbon::setTestNow('2026-10-14 00:15:00');
        app(DailyAutomation::class)->run();
        Http::assertSentCount(1);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'overdue'));

        Carbon::setTestNow('2026-10-15 00:15:00');
        app(DailyAutomation::class)->run();
        $this->assertSame('2026-10-19', $invoice->fresh()->autopay_retry_at->toDateString());

        Carbon::setTestNow('2026-10-19 00:15:00');
        app(DailyAutomation::class)->run();
        $invoice->refresh();
        $this->assertNull($invoice->autopay_retry_at);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->bodyHtml, 'We will not try again by ourselves'));

        Carbon::setTestNow('2026-10-25 00:15:00');
        app(DailyAutomation::class)->run();
        Http::assertSentCount(3);
    }

    public function test_when_the_bank_wants_the_client_to_confirm_it_is_not_tried_again(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['error' => ['code' => 'authentication_required', 'message' => 'This payment requires authentication.', 'payment_intent' => ['id' => 'pi_3ds', 'status' => 'requires_payment_method']]], 402)]);
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertNull($invoice->autopay_retry_at);
        $this->assertSame('Your bank wants you to confirm this payment yourself.', $invoice->autopay_error);
        $this->actingAs($client, 'web')->get(route('client.invoices.show', $invoice))->assertSee('Please pay this invoice here.');
    }

    public function test_clients_get_an_email_before_a_charge_and_before_their_card_expires(): void
    {
        Http::fake();
        $client = $this->client();
        $this->saveCard($client, ['expires_month' => 10, 'expires_year' => 2026]);
        $invoice = $this->renewalInvoice($client, dueIn: 3);

        app(DailyAutomation::class)->run();
        app(DailyAutomation::class)->run();

        Mail::assertSent(TemplatedMessage::class, 1 + 1);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Invoice '.$invoice->displayNumber().' will be paid automatically on 15 Oct 2026');
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Your saved card expires soon' && str_contains($mail->bodyHtml, 'October 2026'));
        Http::assertNothingSent();
    }

    public function test_only_renewals_of_clients_with_automatic_payments_on_are_charged(): void
    {
        Http::fake();
        $client = $this->client(['auto_pay' => false]);
        $this->saveCard($client);
        $this->renewalInvoice($client);
        $other = $this->client(['email' => 'other@example.test']);
        $this->saveCard($other, ['reference' => 'pm_other']);
        app(InvoiceManager::class)->create($other, [['description' => 'One-off work', 'amount' => 5000]], dueAt: today());

        app(DailyAutomation::class)->run();

        Http::assertNothingSent();
    }

    public function test_a_client_turns_automatic_payments_off_and_removes_the_card(): void
    {
        Http::fake(['api.stripe.com/v1/payment_methods/pm_visa/detach' => Http::response(['id' => 'pm_visa'])]);
        $client = $this->client();
        $method = $this->saveCard($client);

        $this->actingAs($client, 'web')->put(route('client.account.payment-methods.automatic'), ['auto_pay' => '0'])->assertSessionHas('status');
        $this->assertFalse($client->fresh()->auto_pay);

        $this->delete(route('client.account.payment-methods.destroy', $method))->assertSessionHas('status');
        $this->assertSame(0, PaymentMethod::query()->count());
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/payment_methods/pm_visa/detach'));

        $someoneElse = $this->saveCard($this->client(['email' => 'other@example.test']), ['reference' => 'pm_other']);
        $this->delete(route('client.account.payment-methods.destroy', $someoneElse))->assertNotFound();
    }

    public function test_staff_charge_an_invoice_now_without_changing_the_schedule(): void
    {
        Http::fakeSequence('api.stripe.com/v1/payment_intents')
            ->push(['error' => ['code' => 'card_declined', 'message' => 'Your card was declined.']], 402)
            ->push(['id' => 'pi_staff', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd']);
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client, dueIn: 5);
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view', 'billing.view', 'billing.manage'])->create(['name' => 'Mer Las']));

        $this->get(route('admin.invoices.show', $invoice))->assertOk()->assertSee('Charge now')->assertSee('Visa •••• 4242');
        $this->post(route('admin.invoices.charge', $invoice))->assertSessionHas('error', 'The charge did not work: Your card was declined.');
        $this->assertSame(0, $invoice->fresh()->autopay_attempts);

        $this->post(route('admin.invoices.charge', $invoice))->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->get(route('admin.clients.show', $client))->assertSee('Saved payment methods')->assertSee('Automatic payments on');
    }

    public function test_paypal_accounts_are_saved_while_paying_and_charged_later(): void
    {
        $this->enableGateway('paypal', ['mode' => 'sandbox', 'client_id' => 'id', 'client_secret' => 'secret']);
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'token']),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER1/capture' => Http::response([
                'id' => 'ORDER1', 'status' => 'COMPLETED',
                'payment_source' => ['paypal' => ['email_address' => 'raz@example.test', 'attributes' => ['vault' => ['id' => 'VAULT1', 'status' => 'VAULTED', 'customer' => ['id' => 'CUST1']]]]],
                'purchase_units' => [['custom_id' => '1', 'payments' => ['captures' => [['id' => 'CAP1', 'status' => 'COMPLETED', 'amount' => ['value' => '12.99', 'currency_code' => 'USD']]]]]],
            ]),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER2', 'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP2', 'status' => 'COMPLETED', 'amount' => ['value' => '12.99', 'currency_code' => 'USD']]]]]],
            ]),
        ]);
        $client = $this->client();
        $first = $this->renewalInvoice($client);
        $this->assertSame(1, $first->id);

        $this->actingAs($client, 'web')->get(route('client.invoices.return', [$first, 'paypal']).'?token=ORDER1');

        $method = PaymentMethod::query()->sole();
        $this->assertSame(['gateway' => 'paypal', 'reference' => 'VAULT1', 'customer_reference' => 'CUST1'], $method->only('gateway', 'reference', 'customer_reference'));
        $this->assertSame('PayPal (raz@example.test)', $method->label());

        $second = $this->renewalInvoice($client, key: 'service-1-2026-11-12');
        app(DailyAutomation::class)->run();

        $this->assertSame(InvoiceStatus::Paid, $second->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api-m.sandbox.paypal.com/v2/checkout/orders'
            && ($request['payment_source']['paypal']['vault_id'] ?? null) === 'VAULT1'
            && str_starts_with($request->header('PayPal-Request-Id')[0] ?? '', 'nuvabill-invoice-'.$second->id));
    }

    public function test_staff_set_the_schedule(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.settings.autopay.edit'))->assertOk()->assertSee('Can charge automatically');
        $this->put(route('admin.settings.autopay.update'), ['autopay' => '1', 'days_before' => '2', 'notice_days' => '0', 'retry_days' => '7, 2, 2, 99', 'offer_save' => '0', 'card_notice' => '1'])->assertSessionHas('status');

        $this->assertSame([2, 7], setting('billing.autopay_retry_days'));
        $this->assertSame(2, setting('billing.autopay_days_before'));
        $this->assertFalse(setting('billing.autopay_offer_save'));
    }

    public function test_a_payment_the_bank_is_still_processing_is_settled_by_the_webhook_once(): void
    {
        Http::fake(['api.stripe.com/v1/payment_intents' => Http::response(['id' => 'pi_slow', 'status' => 'processing', 'amount' => 1299, 'currency' => 'usd'])]);
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        $this->assertSame(0, app(DailyAutomation::class)->run()['charge_failed']);

        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame(0, $invoice->autopay_attempts);
        $this->assertSame('pi_slow', $invoice->autopay_pending['reference']);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));

        $event = $this->intentSucceededEvent('pi_slow', $invoice);
        $this->postStripeWebhook($event)->assertOk();
        $this->postStripeWebhook($event)->assertOk();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(['pi_slow'], $invoice->transactions()->pluck('reference')->all());

        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();
        Http::assertSentCount(1);
    }

    public function test_a_processing_payment_is_checked_before_the_next_try(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'pi_slow', 'status' => 'processing', 'amount' => 1299, 'currency' => 'usd'])
            : Http::response(['id' => 'pi_slow', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd', 'metadata' => ['invoice_id' => '1']]));
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();
        Carbon::setTestNow('2026-10-13 00:15:00');
        $this->assertSame(1, app(DailyAutomation::class)->run()['charged']);

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(['pi_slow'], $invoice->transactions()->pluck('reference')->all());
        $this->assertNull($invoice->fresh()->autopay_pending);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.stripe.com/v1/payment_intents/pi_slow');
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_a_processing_payment_that_fails_later_follows_the_retry_schedule(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['id' => 'pi_slow', 'status' => 'processing', 'amount' => 1299, 'currency' => 'usd'])
            : Http::response(['id' => 'pi_slow', 'status' => 'requires_payment_method', 'metadata' => ['invoice_id' => '1'], 'last_payment_error' => ['message' => 'Your bank account has insufficient funds.']]));
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();
        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $invoice->refresh();
        $this->assertSame(1, $invoice->autopay_attempts);
        $this->assertSame('2026-10-16', $invoice->autopay_retry_at->toDateString());
        $this->assertSame('Your bank account has insufficient funds.', $invoice->autopay_error);
        $this->assertNull($invoice->autopay_pending);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));
    }

    public function test_no_answer_from_stripe_is_checked_and_sent_again_with_the_same_key(): void
    {
        $keys = [];
        Http::fake(function (Request $request) use (&$keys) {
            if ($request->method() === 'POST') {
                $keys[] = $request->header('Idempotency-Key')[0];

                return count($keys) === 1
                    ? throw new ConnectionException('Operation timed out')
                    : Http::response(['id' => 'pi_auto', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd']);
            }

            // Stripe never got the first try.
            return Http::response(['data' => []]);
        });
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();
        $this->assertSame(0, $invoice->fresh()->autopay_attempts);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);

        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request['customer'] === 'cus_raz');
        $this->assertSame(['nuvabill-invoice-1-1299-try-0', 'nuvabill-invoice-1-1299-try-0'], $keys);
    }

    public function test_a_try_stripe_took_before_the_answer_was_lost_is_found_and_not_charged_again(): void
    {
        Http::fake(fn (Request $request) => $request->method() === 'POST'
            ? Http::response(['error' => ['type' => 'api_error', 'message' => 'Something went wrong.']], 500)
            : Http::response(['data' => [
                ['id' => 'pi_other', 'status' => 'succeeded', 'amount_received' => 500, 'currency' => 'usd', 'metadata' => ['invoice_id' => '9', 'attempt' => 'invoice-9-500-try-0']],
                ['id' => 'pi_lost', 'status' => 'succeeded', 'amount_received' => 1299, 'currency' => 'usd', 'metadata' => ['invoice_id' => '1', 'attempt' => 'invoice-1-1299-try-0']],
            ]]));
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        app(DailyAutomation::class)->run();
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => str_contains($mail->subjectLine, 'We could not charge'));

        Carbon::setTestNow('2026-10-13 00:15:00');
        app(DailyAutomation::class)->run();

        $this->assertSame(['pi_lost'], $invoice->transactions()->pluck('reference')->all());
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertCount(1, Http::recorded(fn (Request $request): bool => $request->method() === 'POST'));
    }

    public function test_a_payment_webhook_for_another_stripe_customer_is_ignored(): void
    {
        Http::fake();
        $client = $this->client();
        $this->saveCard($client);
        $invoice = $this->renewalInvoice($client);

        $this->postStripeWebhook($this->intentSucceededEvent('pi_stranger', $invoice, customer: 'cus_stranger'))->assertOk();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame(0, $invoice->transactions()->count());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function client(array $attributes = []): Client
    {
        return Client::factory()->create($attributes + ['first_name' => 'Raz', 'last_name' => '', 'email' => 'raz@example.test', 'currency' => 'USD']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveCard(Client $client, array $attributes = []): PaymentMethod
    {
        return PaymentMethod::query()->create($attributes + [
            'client_id' => $client->id, 'gateway' => 'stripe', 'type' => PaymentMethod::TYPE_CARD, 'reference' => 'pm_visa',
            'customer_reference' => 'cus_raz', 'brand' => 'visa', 'last4' => '4242', 'expires_month' => 8, 'expires_year' => 2028, 'is_default' => true,
        ]);
    }

    private function intentSucceededEvent(string $id, Invoice $invoice, string $customer = 'cus_raz'): string
    {
        return (string) json_encode(['type' => 'payment_intent.succeeded', 'data' => ['object' => [
            'id' => $id, 'status' => 'succeeded', 'amount_received' => $invoice->total, 'currency' => 'usd', 'customer' => $customer,
            'metadata' => ['invoice_id' => (string) $invoice->id, 'autopay' => '1'],
        ]]]);
    }

    private function postStripeWebhook(string $payload): TestResponse
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        return $this->call('POST', route('webhooks.gateway', 'stripe'), [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
            'CONTENT_TYPE' => 'application/json',
        ], $payload);
    }

    private function renewalInvoice(Client $client, int $dueIn = 0, ?string $key = null): Invoice
    {
        return app(InvoiceManager::class)->create($client, [[
            'description' => 'Business Hosting',
            'amount' => 1299,
            'billing_key' => $key ?? 'service-'.$client->id.'-'.today()->addDays($dueIn)->toDateString(),
        ]], dueAt: today()->addDays($dueIn), currency: 'USD');
    }
}
