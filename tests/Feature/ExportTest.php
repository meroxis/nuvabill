<?php

namespace Tests\Feature;

use App\Billing\PaymentRecorder;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Support\CsvExport;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CSV exports for accountants.
 */
class ExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    public function test_invoices_in_the_date_range_are_exported(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.view'])->create());
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        Invoice::factory()->for($client)->create(['number' => 'INV-0100', 'issued_at' => '2026-08-10', 'total' => 1250, 'subtotal' => 1250]);
        Invoice::factory()->for($client)->create(['number' => 'INV-0200', 'issued_at' => '2026-09-10']);

        $response = $this->get(route('admin.exports.download', ['type' => 'invoices', 'from' => '2026-08-01', 'to' => '2026-08-31']));
        $csv = $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBFNumber,", $csv);
        $this->assertStringContainsString('INV-0100', $csv);
        $this->assertStringContainsString('Mer Las', $csv);
        $this->assertStringContainsString('12.50', $csv);
        $this->assertStringNotContainsString('INV-0200', $csv);
    }

    public function test_cells_that_would_run_as_formulas_are_kept_as_text(): void
    {
        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", CsvExport::cell('=HYPERLINK("http://evil.test")'));
        $this->assertSame("'+1+2", CsvExport::cell('+1+2'));
        $this->assertSame("'@SUM(A1)", CsvExport::cell('@SUM(A1)'));
        $this->assertSame('-12.50', CsvExport::cell('-12.50'));
        $this->assertSame('Mer Las', CsvExport::cell('Mer Las'));

        $this->signInAdmin(Admin::factory()->withPermissions(['billing.view', 'clients.manage'])->create());
        Client::factory()->create(['first_name' => '=cmd|calc', 'company_name' => null]);
        $csv = $this->get(route('admin.exports.download', ['type' => 'clients']))->assertOk()->streamedContent();

        $this->assertStringContainsString("'=cmd|calc", $csv);
    }

    public function test_refunds_are_exported_with_the_payments(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.manage'])->create());
        $invoice = Invoice::factory()->create(['total' => 1000]);
        app(PaymentRecorder::class)->record($invoice, 1000, 'banktransfer', 'BANK-1');
        $this->post(route('admin.invoices.refund', $invoice));

        $csv = $this->get(route('admin.exports.download', ['type' => 'payments']))->assertOk()->streamedContent();

        $this->assertStringContainsString('BANK-1', $csv);
        $this->assertStringContainsString(',10.00,', $csv);
        $this->assertStringContainsString(',-10.00,', $csv);
    }

    public function test_client_lists_need_the_right_to_manage_clients(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['billing.view'])->create());

        $this->get(route('admin.exports.index'))->assertOk()->assertDontSee('value="clients"', false);
        $this->get(route('admin.exports.download', ['type' => 'clients']))->assertSessionHasErrors('type');

        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create());
        $this->get(route('admin.exports.index'))->assertForbidden();
    }
}
