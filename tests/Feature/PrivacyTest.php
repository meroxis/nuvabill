<?php

namespace Tests\Feature;

use App\Enums\ClientStatus;
use App\Enums\QuoteStatus;
use App\Enums\ServiceStatus;
use App\Mail\TemplateMailer;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\PaymentMethod;
use App\Models\Quote;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use App\Support\Activity;
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
