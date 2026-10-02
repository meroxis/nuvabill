<?php

namespace Tests\Feature\Admin;

use App\Billing\CreditNotes;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\Wallet;
use App\Http\Controllers\Admin\DashboardController;
use App\Models\Client;
use App\Models\CreditNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The dashboard counts money when it comes in from outside: funds added to a wallet count once,
 * not again when they pay an invoice.
 */
class DashboardRevenueTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_funds_count_once_and_refunds_only_when_money_goes_out(): void
    {
        Mail::fake();
        $this->signInAdmin();
        $this->setSettings(['billing.currency' => 'USD', 'wallet.enabled' => true]);
        $client = Client::factory()->create(['currency' => 'USD']);
        $payments = app(PaymentRecorder::class);

        // $10 comes in as wallet funds, then pays a hosting invoice.
        $payments->record(app(Wallet::class)->topUp($client, 1000), 1000, 'banktransfer', 'bank-funds');
        $hosting = app(InvoiceManager::class)->create($client, [['description' => 'Hosting', 'amount' => 1000]]);
        $this->assertSame(1000, app(Wallet::class)->pay($hosting));
        $this->assertSame(1000, $this->revenue());

        // $5 paid by bank, $2 of it sent back: $3 more.
        $domain = app(InvoiceManager::class)->create($client, [['description' => 'Domain', 'amount' => 500]]);
        $payments->record($domain, 500, 'banktransfer', 'bank-domain');
        app(CreditNotes::class)->issue($domain, 200, CreditNote::METHOD_REFUND);
        $this->assertSame(1300, $this->revenue());

        // Money paid from the wallet going back into it never left.
        app(CreditNotes::class)->issue($hosting->fresh(), 1000, CreditNote::METHOD_REFUND);
        $this->assertSame(1000, $client->fresh()->credit);
        $this->assertSame(1300, $this->revenue());
    }

    private function revenue(): int
    {
        $this->get(route('admin.dashboard'))->assertOk();
        $data = app(DashboardController::class)()->getData();
        $this->assertSame($data['revenueThisMonth'], $data['chart']['bars'][11]['value'], 'The chart shows the same month total.');

        return $data['revenueThisMonth'];
    }
}
