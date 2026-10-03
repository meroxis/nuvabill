<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\ImportMapping;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Models\TicketReply;
use App\Support\Activity;
use App\Support\ClientPrivacy;
use App\Support\TicketDesk;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Privacy tools: a client's data as a file, erasing it on request, and keeping invoices as the law
 * requires.
 */
class PrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
    }

    public function test_staff_and_the_client_download_the_clients_data(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'notes' => 'Prefers phone calls', 'password' => 'secret-password-1']);
        Invoice::factory()->for($client)->create(['number' => 'INV-0042']);
        app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'Email problem', 'My email does not work.');

        $this->signInAdmin(Admin::factory()->withPermissions(['clients.manage'])->create());
        $staffCopy = json_decode($this->get(route('admin.clients.data', $client))->assertOk()->streamedContent(), true);

        $this->assertSame('Raz', $staffCopy['profile']['first_name']);
        $this->assertSame('Prefers phone calls', $staffCopy['profile']['staff_notes']);
        $this->assertSame('INV-0042', $staffCopy['invoices'][0]['number']);
        $this->assertSame('My email does not work.', $staffCopy['tickets'][0]['messages'][0]['message']);
        $this->assertStringNotContainsString('password', json_encode($staffCopy['profile']));

        $ownCopy = json_decode($this->actingAs($client, 'web')->get(route('client.account.data'))->assertOk()->streamedContent(), true);
        $this->assertArrayNotHasKey('staff_notes', $ownCopy['profile']);
    }

    public function test_erasing_waits_for_active_services_and_keeps_invoices(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'merlas@example.test', 'phone' => '+1 555 0100', 'address_1' => '12 Cloud Street']);
        $service = Service::factory()->for($client)->create();
        $invoice = Invoice::factory()->for($client)->paid()->create();
        app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'Help', 'Please help.');
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.manage'])->create());

        $this->get(route('admin.clients.show', $client))->assertOk()->assertSee('Terminate or cancel the client&#039;s services first.', false);
        $this->post(route('admin.clients.erase', $client), ['confirm' => 'ERASE'])->assertSessionHas('error');
        $this->assertFalse($client->fresh()->isErased());

        $service->update(['status' => ServiceStatus::Terminated]);
        $this->post(route('admin.clients.erase', $client), ['confirm' => 'erase'])->assertSessionHasErrors('confirm');
        $this->post(route('admin.clients.erase', $client), ['confirm' => 'ERASE'])->assertSessionHas('status');

        $client->refresh();
        $this->assertTrue($client->isErased());
        $this->assertSame(ClientStatus::Closed, $client->status);
        $this->assertSame("erased-{$client->id}@erased.invalid", $client->email);
        $this->assertNull($client->phone);
        $this->assertSame(0, Ticket::query()->where('client_id', $client->id)->count());

        // The invoice stays, with the name and address the law needs, but no email address.
        $this->assertModelExists($invoice);
        $this->assertSame('Mer Las', $client->name);
        $this->assertSame('12 Cloud Street', $client->address_1);
        $html = view('pdf.invoice', ['invoice' => $invoice->fresh(['items', 'client', 'transactions']), 'company' => ['name' => 'YourHost', 'email' => 'billing@example.test', 'address' => null, 'phone' => null, 'tax_id' => null], 'accent' => '#000', 'showPoweredBy' => false])->render();
        $this->assertStringContainsString('Mer Las', $html);
        $this->assertStringNotContainsString('erased.invalid', $html);

        // No more emails, and no more sign-ins.
        Mail::fake();
        $this->assertFalse(app(TemplateMailer::class)->send('client.welcome', $client));
        Mail::assertNothingSent();
        $this->post(route('client.login'), ['email' => 'merlas@example.test', 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest('web');
    }

    public function test_a_client_without_invoices_loses_the_name_too(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'company_name' => 'Raz Studio']);
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.manage'])->create());

        $this->post(route('admin.clients.erase', $client), ['confirm' => 'ERASE'])->assertSessionHas('status');

        $client->refresh();
        $this->assertSame('Erased', $client->first_name);
        $this->assertNull($client->company_name);
    }

    public function test_the_clients_own_copy_leaves_out_staff_tags_draft_quotes_and_staff_notes_in_the_log(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz', 'tags' => ['Abuse']]);
        Quote::factory()->for($client)->create(['subject' => 'Unsent draft price', 'status' => QuoteStatus::Draft]);
        Quote::factory()->for($client)->create(['subject' => 'Sent offer', 'status' => QuoteStatus::Sent]);
        // Written while the client places an order, so the client is the actor.
        Activity::log('order.review', 'Order #1 needs a review: The email address is from a throwaway email service.', $client, $client, $client);
        Activity::log('service.module_failed', 'Could not create service #1: WHM API said quota exceeded', $client, $client, $client);
        Activity::log('client.login', 'Raz signed in with a password', $client, $client, $client);

        $ownJson = $this->actingAs($client, 'web')->get(route('client.account.data'))->assertOk()->streamedContent();
        $own = json_decode($ownJson, true);

        $this->assertArrayNotHasKey('tags', $own['profile']);
        $this->assertSame(['Sent offer'], array_column($own['quotes'], 'subject'));
        $this->assertStringNotContainsString('Unsent draft price', $ownJson);
        $this->assertStringNotContainsString('throwaway email service', $ownJson);
        $this->assertStringNotContainsString('WHM API said', $ownJson);
        $this->assertContains('client.login', array_column($own['activity'], 'action'));

        // Staff still get everything.
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.manage'])->create());
        $staffJson = $this->get(route('admin.clients.data', $client))->assertOk()->streamedContent();
        $staff = json_decode($staffJson, true);

        $this->assertSame(['Abuse'], $staff['profile']['tags']);
        $this->assertStringContainsString('Unsent draft price', $staffJson);
        $this->assertContains('order.review', array_column($staff['activity'], 'action'));
    }

    public function test_erasing_removes_the_last_ip_and_personal_text_from_the_activity_log(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'merlas@example.test']);

        $this->post(route('client.login'), ['email' => 'merlas@example.test', 'password' => 'password'], ['REMOTE_ADDR' => '203.0.113.5'])->assertSessionHasNoErrors();
        $this->assertSame('203.0.113.5', $client->fresh()->last_login_ip);
        app(TicketDesk::class)->open($client, TicketDepartment::query()->firstOrFail(), 'Home address change', 'I moved.');
        auth('web')->logout();

        $this->signInAdmin(Admin::factory()->withPermissions(['clients.manage'])->create());
        $this->post(route('admin.clients.erase', $client), ['confirm' => 'ERASE'])->assertSessionHas('status')->assertSessionMissing('error');

        $client->refresh();
        $this->assertNull($client->last_login_ip);
        $this->assertNull($client->last_login_at);

        $tiedToClient = ActivityLog::query()->where(fn ($query) => $query->where('client_id', $client->id)
            ->orWhere(fn ($query) => $query->where('actor_type', 'client')->where('actor_id', $client->id)));
        $this->assertTrue((clone $tiedToClient)->where('action', 'client.login')->exists(), 'The entry itself stays for the audit trail.');
        $this->assertSame(0, (clone $tiedToClient)->where('description', 'like', '%Mer Las%')->count());
        $this->assertSame(0, (clone $tiedToClient)->where('description', 'like', '%Home address change%')->count());
        $this->assertSame(0, (clone $tiedToClient)->whereNotNull('ip_address')->count());
    }

    public function test_erasing_marks_the_client_so_an_import_never_brings_it_back(): void
    {
        $client = Client::factory()->create(['first_name' => 'Raz']);
        $other = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        // Imported from two systems, and linked by email from a second WHMCS client by an earlier version.
        foreach ([['whmcs', 'client', 17], ['whmcs', 'client_link', 17], ['whmcs', 'client', 18], ['whmcs', 'client_link', 18], ['blesta', 'client', 4]] as [$source, $entity, $sourceId]) {
            ImportMapping::query()->create(['source' => $source, 'entity' => $entity, 'source_id' => $sourceId, 'local_id' => $client->id]);
        }
        ImportMapping::query()->create(['source' => 'whmcs', 'entity' => 'client', 'source_id' => 19, 'local_id' => $other->id]);
        ImportMapping::query()->create(['source' => 'whmcs', 'entity' => 'service', 'source_id' => 17, 'local_id' => 5]);

        app(ClientPrivacy::class)->erase($client, Admin::factory()->create());

        $erased = ImportMapping::query()->where('entity', 'client_erased')->orderBy('source')->orderBy('source_id')->get(['source', 'source_id', 'local_id']);
        $this->assertSame([['blesta', 4, $client->id], ['whmcs', 17, $client->id], ['whmcs', 18, $client->id]], $erased->map(fn (ImportMapping $mapping): array => [$mapping->source, $mapping->source_id, $mapping->local_id])->all());
        $this->assertFalse(ImportMapping::query()->whereIn('entity', ['client', 'client_link'])->where('local_id', $client->id)->exists());
        $this->assertTrue(ImportMapping::query()->where(['entity' => 'client', 'source_id' => 19, 'local_id' => $other->id])->exists(), 'Other clients keep their mapping');
        $this->assertTrue(ImportMapping::query()->where(['entity' => 'service', 'source_id' => 17])->exists());
    }

    public function test_the_upgrade_erases_again_what_an_import_put_back_and_keeps_what_invoices_show(): void
    {
        // Erased before this version, then an import run put the data back and opened the client again.
        $raz = Client::factory()->create(['first_name' => 'Raz', 'last_name' => '', 'company_name' => 'Raz Studio', 'address_1' => '12 Cloud Street', 'phone' => '+9647501234567', 'notes' => 'Prefers phone calls', 'tags' => ['VIP'], 'status' => ClientStatus::Active]);
        $raz->forceFill(['erased_at' => now()->subMonth()])->save();
        Invoice::factory()->for($raz)->paid()->create();
        $mer = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'phone' => '+9647701112233', 'notes' => 'Prefers email']);

        $imported = Ticket::factory()->create(['client_id' => $raz->id, 'subject' => 'Please close my account']);
        $openedHere = Ticket::factory()->create(['client_id' => $raz->id, 'subject' => 'Refund for the last invoice']);
        $mersTicket = Ticket::factory()->create(['client_id' => $mer->id, 'subject' => 'A new domain']);

        foreach ([$imported, $openedHere, $mersTicket] as $ticket) {
            TicketReply::query()->create(['ticket_id' => $ticket->id, 'author_type' => 'client', 'author_id' => $ticket->client_id, 'message' => 'Hello']);
        }

        foreach ([['client', 17, $raz->id], ['ticket', 9, $imported->id], ['client', 18, $mer->id], ['ticket', 10, $mersTicket->id]] as [$entity, $sourceId, $localId]) {
            ImportMapping::query()->create(['source' => 'whmcs', 'entity' => $entity, 'source_id' => $sourceId, 'local_id' => $localId]);
        }

        (require database_path('migrations/2027_07_02_000117_import_mark_erased_clients.php'))->up();

        $raz->refresh();
        $this->assertSame('Raz', $raz->first_name, 'The paid invoice keeps the name, company and address');
        $this->assertSame('Raz Studio', $raz->company_name);
        $this->assertSame('12 Cloud Street', $raz->address_1);
        $this->assertNull($raz->phone);
        $this->assertNull($raz->notes);
        $this->assertNull($raz->tags);
        $this->assertSame(ClientStatus::Closed, $raz->status);
        $this->assertTrue($raz->isErased());
        $this->assertSame($raz->id, ImportMapping::query()->where(['source' => 'whmcs', 'entity' => 'client_erased', 'source_id' => 17])->value('local_id'));

        $this->assertModelMissing($imported);
        $this->assertSame(0, TicketReply::query()->where('ticket_id', $imported->id)->count());
        $this->assertModelExists($openedHere);
        $this->assertSame(1, TicketReply::query()->where('ticket_id', $openedHere->id)->count(), 'A ticket staff opened here stays');

        $mer->refresh();
        $this->assertSame('+9647701112233', $mer->phone, 'Clients that were not erased stay as they are');
        $this->assertSame('Prefers email', $mer->notes);
        $this->assertModelExists($mersTicket);
        $this->assertSame(1, TicketReply::query()->where('ticket_id', $mersTicket->id)->count());
    }

    public function test_erasing_tells_staff_about_a_card_the_gateway_did_not_remove(): void
    {
        $this->enableGateway('stripe', ['secret_key' => 'sk_test_123', 'webhook_secret' => 'whsec_test']);
        Http::fake(['api.stripe.com/v1/payment_methods/pm_visa/detach' => Http::response(['error' => ['message' => 'Try again later']], 500)]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);
        PaymentMethod::query()->create([
            'client_id' => $client->id, 'gateway' => 'stripe', 'type' => PaymentMethod::TYPE_CARD, 'reference' => 'pm_visa',
            'customer_reference' => 'cus_merlas', 'brand' => 'visa', 'last4' => '4242', 'expires_month' => 8, 'expires_year' => 2028, 'is_default' => true,
        ]);
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.manage'])->create());

        $this->post(route('admin.clients.erase', $client), ['confirm' => 'ERASE'])
            ->assertSessionHas('status')
            ->assertSessionHas('error', 'The payment gateway did not remove these saved methods. Remove them there by hand: Visa •••• 4242 (stripe: pm_visa)');

        $this->assertTrue($client->fresh()->isErased());
        $this->assertSame(0, PaymentMethod::query()->count());
        $this->assertStringContainsString('Visa •••• 4242 (stripe: pm_visa)', (string) ActivityLog::query()->where('action', 'client.erased')->value('description'));
    }

    public function test_clients_ask_for_erasure_with_one_ticket(): void
    {
        $client = Client::factory()->create();

        $this->actingAs($client, 'web')->post(route('client.account.erase-request'))->assertRedirect();
        $this->post(route('client.account.erase-request'))->assertSessionHas('status', 'We already have your request. We answer here.');

        $this->assertSame(1, $client->tickets()->where('subject', 'Please erase my personal data')->count());
    }

    public function test_only_staff_who_manage_clients_can_erase(): void
    {
        $client = Client::factory()->create();
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create());

        $this->post(route('admin.clients.erase', $client), ['confirm' => 'ERASE'])->assertForbidden();
        $this->get(route('admin.clients.data', $client))->assertForbidden();
        $this->assertFalse($client->fresh()->isErased());
    }
}
