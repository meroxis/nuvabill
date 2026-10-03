<?php

namespace Tests\Feature\Billing;

use App\Enums\InvoiceStatus;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Draft invoices (for example from an import) are not issued yet, so clients never see them.
 */
class DraftInvoicesHiddenTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_invoices_are_not_listed_on_the_service_and_domain_pages(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $domain = Domain::factory()->create(['client_id' => $client->id]);

        $draft = Invoice::factory()->create(['client_id' => $client->id, 'status' => InvoiceStatus::Draft, 'number' => null]);
        $draft->items()->create(['type' => InvoiceItem::TYPE_SERVICE, 'service_id' => $service->id, 'description' => 'Hosting', 'amount' => 1000]);
        $draft->items()->create(['type' => InvoiceItem::TYPE_DOMAIN_RENEW, 'domain_id' => $domain->id, 'description' => 'Domain renewal', 'amount' => 1499]);

        $unpaid = Invoice::factory()->create(['client_id' => $client->id, 'number' => 'INV-7001']);
        $unpaid->items()->create(['type' => InvoiceItem::TYPE_SERVICE, 'service_id' => $service->id, 'description' => 'Hosting', 'amount' => 1000]);
        $unpaid->items()->create(['type' => InvoiceItem::TYPE_DOMAIN_RENEW, 'domain_id' => $domain->id, 'description' => 'Domain renewal', 'amount' => 1499]);

        $this->actingAs($client, 'web');

        $this->get(route('client.services.show', $service))->assertOk()->assertDontSee('Draft #'.$draft->id)->assertSee('INV-7001');
        $this->get(route('client.domains.show', $domain))->assertOk()->assertDontSee('Draft #'.$draft->id)->assertSee('INV-7001');
    }
}
