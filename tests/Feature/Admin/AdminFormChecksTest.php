<?php

namespace Tests\Feature\Admin;

use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\ProductAddon;
use App\Models\ProductGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Staff forms save what staff typed, or say what is wrong, instead of failing or saving
 * something else.
 */
class AdminFormChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->setSettings(['billing.currency' => 'USD']);
        $this->signInAdmin();
    }

    public function test_a_product_group_with_an_empty_sort_order_is_saved_with_0(): void
    {
        $this->post(route('admin.product-groups.store'), ['name' => 'Web hosting', 'slug' => '', 'sort_order' => ''])
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, ProductGroup::query()->where('slug', 'web-hosting')->sole()->sort_order);

        $group = ProductGroup::factory()->create(['sort_order' => 5]);
        $this->put(route('admin.product-groups.update', $group), ['name' => $group->name, 'slug' => $group->slug, 'sort_order' => ''])
            ->assertRedirect(route('admin.products.index'));

        $this->assertSame(0, $group->fresh()->sort_order);
    }

    public function test_the_new_invoice_form_shows_the_clients_currency(): void
    {
        $client = $this->client('EUR');

        $this->get(route('admin.invoices.create', ['client' => $client->id]))
            ->assertOk()
            ->assertSee('Invoice for Raz (billed in EUR)')
            ->assertSee('name="currency" value="EUR"', false);
    }

    public function test_an_invoice_typed_in_another_currency_than_the_clients_is_refused(): void
    {
        $client = $this->client('EUR');
        $form = ['client' => (string) $client->id, 'due_at' => today()->toDateString(), 'items' => [['description' => 'Setup', 'amount' => '100.00']], 'send_email' => '1'];

        $this->post(route('admin.invoices.store'), $form + ['currency' => 'USD'])
            ->assertRedirect(route('admin.invoices.create', ['client' => $client->id]))
            ->assertSessionHasErrors('client');
        $this->assertSame(0, Invoice::query()->count());
        Mail::assertNothingSent();

        $this->post(route('admin.invoices.store'), $form + ['currency' => 'EUR'])->assertSessionHasNoErrors();
        $invoice = Invoice::query()->sole();
        $this->assertSame('EUR', $invoice->currency);
        $this->assertSame(10000, $invoice->total);

        // Callers that send no currency work as before.
        $this->post(route('admin.invoices.store'), $form)->assertSessionHasNoErrors();
        $this->assertSame(2, Invoice::query()->count());
    }

    public function test_percent_coupons_take_whole_numbers_only(): void
    {
        $coupon = ['code' => 'HALF', 'type' => 'percent', 'recurring' => 'every', 'is_active' => '1'];

        foreach (['99.5', '0.4', '12.5', '0', '101'] as $value) {
            $this->post(route('admin.coupons.store'), $coupon + ['value' => $value])->assertSessionHasErrors('value');
        }

        $this->assertSame(0, Coupon::query()->count());

        $this->post(route('admin.coupons.store'), $coupon + ['value' => '13'])->assertRedirect(route('admin.coupons.index'));
        $saved = Coupon::query()->sole();
        $this->assertSame(13, $saved->value);
        $this->assertSame(130, $saved->discountOn(1000));

        // Fixed amounts still take cents.
        $this->post(route('admin.coupons.store'), ['code' => 'TENFIFTY', 'type' => 'fixed', 'value' => '10.50', 'recurring' => 'every', 'is_active' => '1'])
            ->assertRedirect(route('admin.coupons.index'));
        $this->assertSame(1050, Coupon::query()->where('code', 'TENFIFTY')->sole()->value);
    }

    public function test_an_add_on_cycle_turned_on_needs_a_price(): void
    {
        $addon = ['name' => 'Daily backups', 'is_visible' => '1'];

        $this->post(route('admin.product-addons.store'), $addon + ['prices' => [
            'monthly' => ['enabled' => '1', 'price' => '', 'setup_fee' => '0'],
            'annually' => ['enabled' => '1', 'setup_fee' => '0'],
            'quarterly' => ['enabled' => '0', 'price' => ''],
        ]])->assertSessionHasErrors(['prices.monthly.price', 'prices.annually.price'])
            ->assertSessionDoesntHaveErrors('prices.quarterly.price');

        $this->assertSame(0, ProductAddon::query()->count());

        // A typed 0 is a free add-on on purpose.
        $this->post(route('admin.product-addons.store'), $addon + ['prices' => [
            'monthly' => ['enabled' => '1', 'price' => '0', 'setup_fee' => '0'],
            'annually' => ['enabled' => '1', 'price' => '20', 'setup_fee' => '0'],
        ]])->assertRedirect(route('admin.product-addons.index'));

        $saved = ProductAddon::query()->sole();
        $this->assertSame(0, $saved->priceFor('USD', BillingCycle::Monthly)->price);
        $this->assertSame(2000, $saved->priceFor('USD', BillingCycle::Annually)->price);
    }

    private function client(string $currency): Client
    {
        return Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null, 'currency' => $currency]);
    }
}
