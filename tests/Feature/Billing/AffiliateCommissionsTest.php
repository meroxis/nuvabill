<?php

namespace Tests\Feature\Billing;

use App\Billing\Affiliates;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Commissions as staff see and handle them: totals per currency, actions that never overwrite a
 * newer status, partial credit notes, and the nightly release.
 */
class AffiliateCommissionsTest extends TestCase
{
    use RefreshDatabase;

    private function merLas(array $attributes = []): Affiliate
    {
        return app(Affiliates::class)->join(Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'currency' => 'USD'] + $attributes));
    }

    private function commission(Affiliate $affiliate, int $amount, string $status, string $currency = 'USD'): AffiliateCommission
    {
        $invoice = Invoice::factory()->paid()->create(['currency' => $currency]);

        return $affiliate->commissions()->create([
            'client_id' => $invoice->client_id,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $status,
            'available_at' => today(),
        ]);
    }

    public function test_the_affiliate_list_does_not_mix_currencies(): void
    {
        $this->signInAdmin();
        $this->setSettings(['billing.currency' => 'USD']);
        $affiliate = $this->merLas();
        $this->commission($affiliate, 2000, AffiliateCommission::STATUS_AVAILABLE);
        $this->commission($affiliate, 3000, AffiliateCommission::STATUS_AVAILABLE, 'EUR');

        $this->get(route('admin.affiliates.index'))
            ->assertOk()
            ->assertSee('$20.00')
            ->assertSee('Commissions available to affiliates now: $20.00, €30.00.')
            ->assertDontSee('$50.00');
    }

    public function test_a_staff_action_never_overwrites_a_commission_that_changed_meanwhile(): void
    {
        $this->signInAdmin();
        $commission = $this->commission($this->merLas(), 1000, AffiliateCommission::STATUS_AVAILABLE);

        // The affiliate moves it to their wallet right after the staff request loaded it.
        $moved = false;
        AffiliateCommission::retrieved(function (AffiliateCommission $loaded) use (&$moved): void {
            if (! $moved) {
                $moved = true;
                AffiliateCommission::query()->whereKey($loaded->id)->update(['status' => AffiliateCommission::STATUS_PAID, 'paid_at' => now()]);
            }
        });

        $this->post(route('admin.affiliates.commission', [$commission, 'cancel']))
            ->assertSessionHas('error', 'This commission changed in the meantime. Reload the page and try again.');

        $this->assertSame(AffiliateCommission::STATUS_PAID, $commission->fresh()->status);
    }

    public function test_partial_credit_notes_cut_the_commission_on_release(): void
    {
        $this->setSettings(['affiliates.enabled' => true, 'affiliates.recurring' => true, 'affiliates.percent' => 10, 'affiliates.hold_days' => 30]);
        $affiliate = $this->merLas();
        $raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'currency' => 'USD']);
        AffiliateReferral::query()->create(['affiliate_id' => $affiliate->id, 'client_id' => $raz->id]);

        $kept = Invoice::factory()->for($raz)->create(['total' => 10000, 'subtotal' => 10000]);
        app(PaymentRecorder::class)->record($kept, 10000, 'banktransfer', 'bank-kept');
        $refunded = Invoice::factory()->for($raz)->create(['total' => 10000, 'subtotal' => 10000]);
        app(PaymentRecorder::class)->record($refunded, 10000, 'banktransfer', 'bank-refunded');
        $this->assertSame([1000, 1000], AffiliateCommission::query()->orderBy('id')->pluck('amount')->all());

        $this->signInAdmin();
        $this->post(route('admin.invoices.credit-notes.store', $kept), ['amount' => '99.00', 'method' => 'none'])->assertSessionHas('status');
        $this->assertSame(InvoiceStatus::Paid, $kept->fresh()->status);
        // Two credit notes that add up to the whole invoice.
        $this->post(route('admin.invoices.credit-notes.store', $refunded), ['amount' => '60.00', 'method' => 'none'])->assertSessionHas('status');
        $this->post(route('admin.invoices.credit-notes.store', $refunded), ['amount' => '40.00', 'method' => 'none'])->assertSessionHas('status');

        $this->travel(31)->days();
        $this->assertSame(1, app(Affiliates::class)->release());

        $commissions = AffiliateCommission::query()->orderBy('id')->get();
        $this->assertSame(AffiliateCommission::STATUS_AVAILABLE, $commissions[0]->status);
        $this->assertSame(10, $commissions[0]->amount, 'The client kept 1% of the invoice, so the commission is 1% of what it was.');
        $this->assertSame(AffiliateCommission::STATUS_CANCELLED, $commissions[1]->status);
    }

    public function test_a_staff_release_also_pays_only_for_what_the_client_kept(): void
    {
        $this->signInAdmin();
        $commission = $this->commission($this->merLas(), 1000, AffiliateCommission::STATUS_PENDING);
        $commission->invoice->update(['total' => 10000, 'subtotal' => 10000, 'amount_paid' => 10000]);
        $this->post(route('admin.invoices.credit-notes.store', $commission->invoice), ['amount' => '50.00', 'method' => 'none'])->assertSessionHas('status');

        $this->post(route('admin.affiliates.commission', [$commission, 'release']))->assertSessionHas('status');

        $this->assertSame(AffiliateCommission::STATUS_AVAILABLE, $commission->fresh()->status);
        $this->assertSame(500, $commission->fresh()->amount);
    }

    public function test_release_handles_more_than_one_page_of_commissions(): void
    {
        $affiliate = $this->merLas();
        $invoice = Invoice::factory()->paid()->create();
        $client = $invoice->client_id;
        $now = now();

        // Each commission needs its own invoice; a plain insert keeps this quick.
        foreach (array_chunk(range(1, 1001), 250) as $chunk) {
            $invoices = array_map(fn (int $i): array => ['number' => 'BULK-'.$i, 'client_id' => $client, 'status' => InvoiceStatus::Paid->value, 'currency' => 'USD', 'subtotal' => 1000, 'tax' => 0, 'total' => 1000, 'amount_paid' => 1000, 'issued_at' => $now->toDateString(), 'due_at' => $now->toDateString(), 'paid_at' => $now, 'created_at' => $now, 'updated_at' => $now], $chunk);
            DB::table('invoices')->insert($invoices);
        }

        $ids = DB::table('invoices')->where('number', 'like', 'BULK-%')->pluck('id');

        foreach ($ids->chunk(250) as $chunk) {
            DB::table('affiliate_commissions')->insert($chunk->map(fn (int $id): array => [
                'affiliate_id' => $affiliate->id, 'client_id' => $client, 'invoice_id' => $id, 'amount' => 100, 'currency' => 'USD',
                'status' => AffiliateCommission::STATUS_PENDING, 'available_at' => today()->toDateString(), 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all());
        }

        $this->assertSame(1001, app(Affiliates::class)->release());
        $this->assertSame(0, AffiliateCommission::query()->where('status', AffiliateCommission::STATUS_PENDING)->count());
    }
}
