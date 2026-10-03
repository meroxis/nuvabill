<?php

namespace Tests\Feature\Billing;

use App\Mail\TemplateMailer;
use App\Models\Client;
use App\Models\Invoice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Amounts are stored with two decimals in every currency. A currency shown in whole units by
 * default (IQD) still shows the fraction when an amount has one, so the amount shown is the
 * amount owed.
 */
class MoneyDisplayTest extends TestCase
{
    use RefreshDatabase;

    private const NBSP = "\u{00A0}";

    public function test_iqd_amounts_with_a_fraction_show_it(): void
    {
        app()->setLocale('en');

        $this->assertSame('IQD'.self::NBSP.'12,962.25', money(1296225, 'IQD'));
        $this->assertSame('IQD'.self::NBSP.'0.25', money(25, 'IQD'));
        $this->assertSame('IQD'.self::NBSP.'12,963', money(1296300, 'IQD'));
        $this->assertSame('-IQD'.self::NBSP.'0.25', money(-25, 'IQD'));
        $this->assertSame('$1,250.00', money(125000, 'USD'));
        $this->assertSame('$0.25', money(25, 'USD'));
    }

    public function test_a_small_iqd_balance_is_not_shown_as_zero(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => null, 'currency' => 'IQD', 'language' => 'en']);
        $invoice = Invoice::factory()->for($client)->create(['currency' => 'IQD', 'subtotal' => 1296225, 'total' => 1296225, 'amount_paid' => 1296200]);

        $this->assertSame('IQD'.self::NBSP.'0.25', TemplateMailer::invoiceContext($invoice)['invoice']['balance']);
        $this->assertSame('IQD'.self::NBSP.'12,962.25', TemplateMailer::invoiceContext($invoice)['invoice']['total']);

        $this->actingAs($client, 'web')->get(route('client.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('IQD'.self::NBSP.'0.25')
            ->assertSee('IQD'.self::NBSP.'12,962.25')
            ->assertDontSee('IQD'.self::NBSP.'0<', false);
    }
}
