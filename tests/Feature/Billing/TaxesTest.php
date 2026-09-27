<?php

namespace Tests\Feature\Billing;

use App\Billing\InvoiceManager;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TaxesTest extends TestCase
{
    use RefreshDatabase;

    public function test_vat_is_added_on_top_for_clients_in_the_rule_country(): void
    {
        $this->setSettings(['tax.enabled' => true]);
        TaxRule::factory()->create(['name' => 'VAT', 'rate' => 2000, 'country' => 'GB']);
        TaxRule::factory()->create(['name' => 'Sales tax', 'rate' => 1000]);
        $product = Product::factory()->priced(1000)->create();
        $client = Client::factory()->create(['country' => 'GB']);

        $this->actingAs($client, 'web')->post(route('cart.store'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain' => 'razstudio.com']);
        $this->get(route('cart.show'))->assertSee('VAT (20%)')->assertSee('$2.00')->assertSee('$12.00');
        $this->post(route('checkout.store'))->assertRedirect();

        $invoice = Order::query()->sole()->invoice;
        $this->assertSame(1000, $invoice->subtotal);
        $this->assertSame(200, $invoice->tax);
        $this->assertSame(1200, $invoice->total);
        $this->assertSame('VAT (20%)', $invoice->taxLabel());
        $this->assertTrue($invoice->items->every(fn ($item) => $item->taxed));
        $this->get(route('client.invoices.show', $invoice))->assertSee('VAT (20%)')->assertSee('$12.00');
    }

    public function test_the_most_exact_rule_wins_and_included_tax_is_taken_from_the_price(): void
    {
        $this->setSettings(['tax.enabled' => true, 'tax.inclusive' => true]);
        TaxRule::factory()->create(['name' => 'Sales tax', 'rate' => 500, 'country' => 'US']);
        TaxRule::factory()->create(['name' => 'Texas sales tax', 'rate' => 825, 'country' => 'US', 'state' => 'TX']);
        $client = Client::factory()->create(['country' => 'US', 'state' => 'tx']);
        $taxed = Service::factory()->for($client)->create(['product_id' => Product::factory()->create(['taxable' => true])->id]);
        $untaxed = Service::factory()->for($client)->create(['product_id' => Product::factory()->create(['taxable' => false])->id]);

        $invoice = app(InvoiceManager::class)->create($client, [
            ['type' => 'service', 'description' => 'Hosting', 'amount' => 1000, 'service_id' => $taxed->id],
            ['type' => 'service', 'description' => 'Backup storage', 'amount' => 500, 'service_id' => $untaxed->id],
        ]);

        $this->assertSame('Texas sales tax', $invoice->tax_name);
        $this->assertSame(1500, $invoice->subtotal);
        $this->assertSame(76, $invoice->tax, '8.25% inside $10.00 is $0.76.');
        $this->assertSame(1500, $invoice->total, 'Included tax does not change what the client pays.');
        $this->assertSame('Texas sales tax (8.25%) included', $invoice->taxLabel());
    }

    public function test_exempt_clients_pay_no_tax_and_old_invoices_keep_theirs(): void
    {
        $this->setSettings(['tax.enabled' => true]);
        $rule = TaxRule::factory()->create(['rate' => 2000]);
        $exempt = Client::factory()->create(['tax_exempt' => true]);
        $client = Client::factory()->create();

        $none = app(InvoiceManager::class)->create($exempt, [['description' => 'Consulting', 'amount' => 10000]]);
        $this->assertNull($none->tax_rate);
        $this->assertSame(10000, $none->total);

        $invoice = app(InvoiceManager::class)->create($client, [['description' => 'Consulting', 'amount' => 10000]]);
        $this->assertSame(12000, $invoice->total);

        $rule->update(['rate' => 500]);
        $this->setSettings(['tax.enabled' => false, 'tax.inclusive' => true]);
        $invoice->recalculate();

        $this->assertSame(2000, $invoice->tax);
        $this->assertSame(12000, $invoice->total);
    }

    public function test_staff_manage_tax_rules_and_untick_tax_on_a_manual_line(): void
    {
        $this->signInAdmin();

        $this->put(route('admin.settings.taxes.settings'), ['enabled' => 1, 'inclusive' => 0, 'domains' => 1, 'id_label' => 'VAT number', 'company_tax_id' => 'GB123456789'])->assertSessionHas('status');
        $this->post(route('admin.settings.taxes.store'), ['name' => 'VAT', 'rate' => '7.5', 'country' => 'DE'])->assertSessionHas('status');
        $rule = TaxRule::query()->sole();
        $this->assertSame(750, $rule->rate);

        $this->put(route('admin.settings.taxes.update', $rule), ['name' => 'VAT', 'rate' => '19', 'country' => 'DE', 'state' => ''])->assertSessionHas('status');
        $this->assertSame(1900, $rule->fresh()->rate);
        $this->get(route('admin.settings.taxes.index'))->assertOk()->assertSee('GB123456789', false);

        $client = Client::factory()->create(['country' => 'DE', 'tax_id' => 'DE999999999']);
        $this->post(route('admin.invoices.store'), [
            'client' => (string) $client->id,
            'due_at' => today()->toDateString(),
            'items' => [
                ['description' => 'Server setup', 'amount' => '100.00', 'taxed' => '1'],
                ['description' => 'Pass-through domain fee', 'amount' => '10.00', 'taxed' => '0'],
            ],
        ])->assertRedirect();

        $invoice = Invoice::query()->sole();
        $this->assertSame(1900, $invoice->tax);
        $this->assertSame(12900, $invoice->total);

        $this->delete(route('admin.settings.taxes.destroy', $rule))->assertSessionHas('status');
        $this->assertSame(0, TaxRule::query()->count());
    }

    public function test_the_one_page_order_form_quotes_tax_for_the_country_a_visitor_chose(): void
    {
        $this->setSettings(['tax.enabled' => true]);
        TaxRule::factory()->create(['name' => 'VAT', 'rate' => 2000, 'country' => 'GB']);
        $product = Product::factory()->priced(1000)->create();

        $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain_action' => 'none', 'country' => 'GB'])
            ->assertOk()
            ->assertJsonPath('tax.label', 'VAT (20%)')
            ->assertJsonPath('tax.amount', '$2.00')
            ->assertJsonPath('total', 1200);

        $this->postJson(route('store.api.quote'), ['product_id' => $product->id, 'billing_cycle' => 'monthly', 'domain_action' => 'none', 'country' => 'US'])
            ->assertOk()
            ->assertJsonPath('tax', null)
            ->assertJsonPath('total', 1000);
    }
}
