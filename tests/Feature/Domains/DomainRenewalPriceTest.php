<?php

namespace Tests\Feature\Domains;

use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Billing\RenewalGenerator;
use App\Enums\InvoiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\ActivityLog;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\TldPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\Registrars\TestRegistrar;
use Tests\TestCase;

/**
 * A client's "Renew" button: never a free renewal at the company's cost when no price is known,
 * and a paid renewal always renews, even for a domain without a next due date.
 */
class DomainRenewalPriceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        app(ExtensionManager::class)->saveSettings('testreg', ['api_key' => 'test'], true);
        TestRegistrar::reset();
    }

    public function test_a_domain_without_any_renewal_price_is_not_renewed_for_free(): void
    {
        $domain = Domain::factory()->withRegistrar('testreg')->create(['recurring_amount' => 0, 'tld' => 'com']);
        $this->assertNoFreeRenewal($domain);
    }

    public function test_a_disabled_extension_price_does_not_make_a_free_renewal(): void
    {
        $this->sellCom(renew: 1499)->update(['is_enabled' => false]);
        $domain = Domain::factory()->withRegistrar('testreg')->create(['recurring_amount' => 0, 'tld' => 'com']);

        $this->assertNoFreeRenewal($domain);
    }

    public function test_a_domain_without_its_own_price_is_billed_the_extension_renew_price(): void
    {
        $this->sellCom(renew: 1499);
        $domain = Domain::factory()->withRegistrar('testreg')->create(['recurring_amount' => 0, 'tld' => 'com']);

        $this->actingAs($domain->client, 'web')->post(route('client.domains.renew', $domain))->assertRedirect();

        $invoice = Invoice::query()->where('client_id', $domain->client_id)->sole();
        $this->assertSame(1499, $invoice->total);
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertSame([], TestRegistrar::$calls, 'Nothing is renewed before the invoice is paid.');
    }

    public function test_staff_get_an_error_instead_of_a_free_renewal_invoice(): void
    {
        $domain = Domain::factory()->withRegistrar('testreg')->create(['recurring_amount' => 0, 'tld' => 'com']);
        $this->signInAdmin();

        $this->post(route('admin.domains.action', [$domain, 'invoice']))
            ->assertSessionHas('error', 'This domain has no renewal price. Set its recurring amount, or renew it without an invoice.');

        $this->assertSame(0, Invoice::query()->count());
        $this->assertSame([], TestRegistrar::$calls);
    }

    public function test_a_zero_domain_renewal_left_unpaid_by_an_older_version_is_not_paid_by_the_nightly_run(): void
    {
        $domain = Domain::factory()->withRegistrar('testreg')->create(['recurring_amount' => 0]);
        $due = $domain->next_due_date;
        $invoice = app(InvoiceManager::class)->create($domain->client, [[
            'type' => InvoiceItem::TYPE_DOMAIN_RENEW,
            'domain_id' => $domain->id,
            'description' => 'Domain renewal',
            'amount' => 0,
            'period_start' => $due,
            'period_end' => $due->addYear()->subDay(),
            'billing_key' => RenewalGenerator::billingKey('domain', $domain->id, $due),
        ]]);

        app(RenewalGenerator::class)->generate();

        $this->assertSame(InvoiceStatus::Unpaid, $invoice->fresh()->status);
        $this->assertSame([], TestRegistrar::$calls);
        $this->assertTrue($domain->fresh()->next_due_date->equalTo($due));
    }

    public function test_paying_a_client_renewal_renews_a_domain_without_a_next_due_date(): void
    {
        $expires = today()->addDays(20)->toImmutable();
        $domain = Domain::factory()->withRegistrar('testreg')->create(['next_due_date' => null, 'expires_at' => $expires, 'recurring_amount' => 1499]);

        $this->actingAs($domain->client, 'web')->post(route('client.domains.renew', $domain))->assertRedirect();
        $invoice = Invoice::query()->where('client_id', $domain->client_id)->sole();

        app(PaymentRecorder::class)->record($invoice, 1499, 'banktransfer');

        $this->assertContains(['renew', $domain->name, 1], TestRegistrar::$calls);
        $this->assertTrue($domain->fresh()->expires_at->equalTo($expires->addYear()));
        $this->assertTrue($domain->fresh()->next_due_date->equalTo($expires->addYear()));
    }

    public function test_paying_a_client_renewal_renews_a_domain_without_a_registrar_or_next_due_date(): void
    {
        $expires = today()->addDays(20)->toImmutable();
        $domain = Domain::factory()->create(['registrar' => null, 'next_due_date' => null, 'expires_at' => $expires, 'recurring_amount' => 1499]);

        $this->actingAs($domain->client, 'web')->post(route('client.domains.renew', $domain))->assertRedirect();
        app(PaymentRecorder::class)->record(Invoice::query()->where('client_id', $domain->client_id)->sole(), 1499, 'banktransfer');

        $this->assertTrue($domain->fresh()->expires_at->equalTo($expires->addYear()));
        $this->assertTrue(ActivityLog::query()->where('action', 'domain.renewed')->where('subject_id', $domain->id)->exists(), 'Staff are told to renew it by hand.');
    }

    /**
     * Pressing Renew twice makes no invoice, calls no registrar and moves no date.
     */
    private function assertNoFreeRenewal(Domain $domain): void
    {
        $due = $domain->next_due_date;
        $expires = $domain->expires_at;
        $this->actingAs($domain->client, 'web');

        foreach ([1, 2] as $try) {
            $this->post(route('client.domains.renew', $domain))
                ->assertRedirect()
                ->assertSessionHas('error', 'This domain has no renewal price. Please open a ticket to renew it.');
        }

        $this->assertSame(0, Invoice::query()->where('client_id', $domain->client_id)->count());
        $this->assertSame([], TestRegistrar::$calls);
        $this->assertTrue($domain->fresh()->next_due_date->equalTo($due));
        $this->assertTrue($domain->fresh()->expires_at->equalTo($expires));
    }

    private function sellCom(int $renew): TldPrice
    {
        return TldPrice::create([
            'tld' => 'com',
            'currency' => 'USD',
            'registrar' => 'testreg',
            'register_price' => 1299,
            'renew_price' => $renew,
            'transfer_price' => 1299,
        ]);
    }
}
