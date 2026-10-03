<?php

namespace Tests\Feature\Billing;

use App\Billing\Affiliates;
use App\Billing\PaymentRecorder;
use App\Models\Affiliate;
use App\Models\AffiliateCommission;
use App\Models\AffiliateReferral;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_referral_pays_and_the_affiliate_moves_the_commission_to_the_wallet(): void
    {
        $this->setSettings(['affiliates.enabled' => true, 'affiliates.percent' => 10, 'affiliates.hold_days' => 30]);
        $mer = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);

        $this->actingAs($mer, 'web')->post(route('client.affiliate.join'))->assertRedirect(route('client.affiliate'));
        $affiliate = Affiliate::query()->sole();
        $this->get(route('client.affiliate'))->assertSee($affiliate->link());
        auth('web')->logout();

        $this->get('/?ref='.strtolower($affiliate->code))->assertCookie(Affiliates::COOKIE, $affiliate->code);
        $this->assertSame(1, $affiliate->fresh()->clicks);

        $this->withCookie(Affiliates::COOKIE, $affiliate->code)->post(route('client.register'), [
            'first_name' => 'Raz', 'last_name' => 'Studio', 'email' => 'raz@razstudio.test', 'country' => 'IQ',
            'password' => 'long-password-1', 'password_confirmation' => 'long-password-1',
        ])->assertRedirect();
        $raz = Client::query()->where('email', 'raz@razstudio.test')->sole();
        $this->assertSame($affiliate->id, AffiliateReferral::query()->sole()->affiliate_id);

        $product = Product::factory()->priced(5000)->create();
        $this->actingAs($raz, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'razstudio.com']);
        $this->post(route('checkout.store'));
        $invoice = Order::query()->sole()->invoice;
        app(PaymentRecorder::class)->record($invoice, $invoice->total, 'banktransfer', 'bank-1');

        $commission = AffiliateCommission::query()->sole();
        $this->assertSame(500, $commission->amount);
        $this->assertSame(AffiliateCommission::STATUS_PENDING, $commission->status);

        $renewal = Invoice::factory()->create(['client_id' => $raz->id, 'total' => 5000, 'subtotal' => 5000]);
        app(PaymentRecorder::class)->record($renewal, 5000, 'banktransfer', 'bank-2');
        $this->assertSame(1, AffiliateCommission::query()->count(), 'Renewals do not earn unless the setting says so.');

        $this->travel(31)->days();
        $this->artisan('nuvabill:cron')->assertSuccessful();
        $this->assertSame(AffiliateCommission::STATUS_AVAILABLE, $commission->fresh()->status);

        // Mer Las in her own browser: the session above belongs to Raz, who has another password.
        $this->flushSession();
        $this->actingAs($mer, 'web')->post(route('client.affiliate.withdraw'))->assertSessionHas('status');
        $this->assertSame(500, $mer->fresh()->credit);
        $this->assertSame(AffiliateCommission::STATUS_PAID, $commission->fresh()->status);
    }

    public function test_refunds_cancel_commissions_and_paused_affiliates_earn_nothing(): void
    {
        $this->setSettings(['affiliates.enabled' => true, 'affiliates.recurring' => true]);
        $affiliate = app(Affiliates::class)->join(Client::factory()->create());
        $referred = Client::factory()->create();
        AffiliateReferral::query()->create(['affiliate_id' => $affiliate->id, 'client_id' => $referred->id]);

        $invoice = Invoice::factory()->create(['client_id' => $referred->id, 'total' => 2000, 'subtotal' => 2000]);
        app(PaymentRecorder::class)->record($invoice, 2000, 'banktransfer', 'bank-3');
        $this->assertSame(200, AffiliateCommission::query()->sole()->amount);

        $this->signInAdmin();
        $this->post(route('admin.invoices.refund', $invoice), ['through_gateway' => '0']);
        $this->assertSame(AffiliateCommission::STATUS_CANCELLED, AffiliateCommission::query()->sole()->status);

        $affiliate->update(['status' => Affiliate::STATUS_SUSPENDED]);
        $second = Invoice::factory()->create(['client_id' => $referred->id, 'total' => 2000, 'subtotal' => 2000]);
        app(PaymentRecorder::class)->record($second, 2000, 'banktransfer', 'bank-4');
        $this->assertSame(1, AffiliateCommission::query()->count());
    }

    public function test_staff_change_the_program_and_manage_commissions(): void
    {
        $this->signInAdmin();

        $this->put(route('admin.affiliates.settings'), ['enabled' => 1, 'recurring' => 0, 'percent' => '15', 'hold_days' => 14, 'cookie_days' => 90])->assertSessionHas('status');
        $this->assertSame(15.0, (float) setting('affiliates.percent'));

        $affiliate = app(Affiliates::class)->join(Client::factory()->create());
        $this->put(route('admin.affiliates.update', $affiliate), ['status' => 'active', 'percent' => '25'])->assertSessionHas('status');
        $this->assertSame(25.0, $affiliate->fresh()->rate());

        // Commissions are earned on paid invoices.
        $invoice = Invoice::factory()->paid()->create(['total' => 1000]);
        $commission = $affiliate->commissions()->create(['client_id' => $invoice->client_id, 'invoice_id' => $invoice->id, 'amount' => 250, 'currency' => 'USD', 'status' => 'pending', 'available_at' => today()->addDays(14)]);

        $this->post(route('admin.affiliates.commission', [$commission, 'paid']))->assertStatus(422);
        $this->post(route('admin.affiliates.commission', [$commission, 'release']))->assertSessionHas('status');
        $this->post(route('admin.affiliates.commission', [$commission, 'paid']))->assertSessionHas('status');
        $this->assertSame(AffiliateCommission::STATUS_PAID, $commission->fresh()->status);

        $this->get(route('admin.affiliates.index'))->assertOk()->assertSee($affiliate->code);
        $this->get(route('admin.affiliates.show', $affiliate))->assertOk()->assertSee('$2.50');
    }
}
