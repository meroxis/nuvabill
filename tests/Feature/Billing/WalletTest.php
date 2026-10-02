<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\Wallet;
use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\CreditTransaction;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use App\Models\TaxRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_client_adds_funds_with_an_untaxed_invoice(): void
    {
        $this->setSettings(['tax.enabled' => true]);
        TaxRule::factory()->create(['rate' => 2000]);
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('client.wallet.store'), ['amount' => '1'])->assertSessionHasErrors('amount');
        $this->post(route('client.wallet.store'), ['amount' => '25.00'])->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame(InvoiceItem::TYPE_CREDIT, $invoice->items()->sole()->type);
        $this->assertSame(2500, $invoice->total, 'Money added to a wallet is not taxed.');

        app(PaymentRecorder::class)->record($invoice, 2500, 'banktransfer', 'bank-1');

        $this->assertSame(2500, $client->fresh()->credit);
        $this->get(route('client.wallet'))->assertOk()->assertSee('$25.00')->assertSee('Added funds with invoice');
    }

    public function test_a_client_pays_an_invoice_from_the_wallet_in_full_or_in_part(): void
    {
        $client = Client::factory()->create(['credit' => 1300]);
        $first = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        $second = app(InvoiceManager::class)->create($client, [['description' => 'Domain', 'amount' => 1000]]);

        $this->actingAs($client, 'web')->get(route('client.invoices.show', $first))->assertSee('Pay $10.00 from my wallet');
        $this->post(route('client.invoices.wallet', $first))->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Paid, $first->fresh()->status);
        $this->assertSame('credit', $first->transactions()->sole()->gateway);

        $this->post(route('client.invoices.wallet', $second))->assertSessionHas('status');
        $this->assertSame(700, $second->fresh()->balance(), 'The last $3.00 went to the second invoice.');
        $this->assertSame(0, $client->fresh()->credit);
        $this->assertSame([-1000, -300], CreditTransaction::query()->orderBy('id')->pluck('amount')->all());

        $this->post(route('client.invoices.wallet', $second))->assertSessionHas('error');
    }

    public function test_renewals_use_the_wallet_by_themselves(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['credit' => 5000]);
        Service::factory()->create(['client_id' => $client->id, 'recurring_amount' => 1200, 'next_due_date' => today()->addDays(3)]);

        $this->artisan('nuvabill:cron')->assertSuccessful();

        $this->assertSame(InvoiceStatus::Paid, Invoice::query()->sole()->status);
        $this->assertSame(3800, $client->fresh()->credit);

        $this->setSettings(['wallet.auto_apply' => false]);
        $other = Client::factory()->create(['credit' => 5000]);
        $invoice = app(InvoiceManager::class)->create($other, [['description' => 'Hosting', 'amount' => 1000]]);
        $this->assertSame(0, app(Wallet::class)->applyAutomatically($invoice));
    }

    public function test_overpayments_and_refunds_go_into_the_wallet(): void
    {
        $client = Client::factory()->create(['credit' => 1000]);
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        app(Wallet::class)->pay($invoice);

        $this->signInAdmin();
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(1000, $client->fresh()->credit, 'Money paid from the wallet goes back into it.');

        $other = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        app(PaymentRecorder::class)->record($other, 1500, 'banktransfer', 'bank-9');
        $this->assertSame(1500, $client->fresh()->credit);
        $this->assertSame('Overpayment on invoice '.$other->number, CreditTransaction::query()->latest('id')->first()->description);
    }

    public function test_every_payment_beyond_the_total_goes_into_the_wallet_once(): void
    {
        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        $payments = app(PaymentRecorder::class);

        $payments->record($invoice, 600, 'banktransfer', 'bank-1');
        $this->assertSame(0, $client->fresh()->credit, 'A part payment is not an overpayment.');

        $payments->record($invoice, 600, 'banktransfer', 'bank-2');
        $this->assertSame(InvoiceStatus::Paid, $invoice->fresh()->status);
        $this->assertSame(200, $client->fresh()->credit);

        // A second, separate payment after the invoice was paid.
        $payments->record($invoice, 1000, 'stripe', 'pi_second');
        $this->assertSame(1200, $client->fresh()->credit);

        // The same payment reported again changes nothing.
        $payments->record($invoice, 1000, 'stripe', 'pi_second');
        $this->assertSame(1200, $client->fresh()->credit);
        $this->assertSame(3, $invoice->transactions()->count());
        $this->assertSame([200, 1000], CreditTransaction::query()->orderBy('id')->pluck('amount')->all());
    }

    public function test_an_overpayment_in_another_currency_goes_in_at_the_staff_rate(): void
    {
        $this->setSettings(['billing.currency' => 'USD', 'currency.rates' => ['EUR' => 0.8]]);
        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]], currency: 'EUR');

        app(PaymentRecorder::class)->record($invoice, 1500, 'banktransfer', 'bank-eur');

        // €5.00 at 1 EUR = 1.25 USD.
        $this->assertSame(625, $client->fresh()->credit);
        $entry = CreditTransaction::query()->sole();
        $this->assertSame('USD', $entry->currency);
        $this->assertSame('Overpayment on invoice '.$invoice->displayNumber().' (€5.00 at 1 EUR = 1.25 USD)', $entry->description);
    }

    public function test_an_overpayment_without_an_exchange_rate_stays_on_the_invoice(): void
    {
        $this->setSettings(['billing.currency' => 'USD', 'currency.rates' => []]);
        $client = Client::factory()->create(['currency' => 'USD']);
        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]], currency: 'GBP');

        app(PaymentRecorder::class)->record($invoice, 1500, 'banktransfer', 'bank-gbp');

        $this->assertSame(0, $client->fresh()->credit, 'Pounds are never added to a dollar wallet as if they were dollars.');
        $this->assertSame(1500, $invoice->fresh()->amount_paid);
        $this->assertDatabaseHas('activity_logs', ['action' => 'payment.overpaid']);
    }

    public function test_staff_add_and_remove_credit_but_never_below_zero(): void
    {
        $admin = $this->signInAdmin();
        $client = Client::factory()->create();

        $this->post(route('admin.clients.wallet', $client), ['amount' => '15', 'reason' => 'Refund for downtime'])->assertSessionHas('status');
        $this->post(route('admin.clients.wallet', $client), ['amount' => '-20', 'reason' => 'Mistake'])->assertSessionHasErrors('amount');
        $this->post(route('admin.clients.wallet', $client), ['amount' => '-5', 'reason' => 'Mistake'])->assertSessionHas('status');

        $this->assertSame(1000, $client->fresh()->credit);
        $this->assertSame($admin->id, CreditTransaction::query()->first()->admin_id);
        $this->get(route('admin.clients.show', $client))->assertSee('Refund for downtime')->assertSee('$10.00');
    }
}
