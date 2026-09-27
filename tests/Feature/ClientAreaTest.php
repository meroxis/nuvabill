<?php

namespace Tests\Feature;

use App\Enums\TicketStatus;
use App\Mail\TemplatedMessage;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ClientAreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_pages_load(): void
    {
        $client = Client::factory()->create();
        $service = Service::factory()->create(['client_id' => $client->id]);
        $invoice = Invoice::factory()->create(['client_id' => $client->id]);
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);

        $this->actingAs($client, 'web');

        foreach ([
            route('client.dashboard'),
            route('client.services.index'),
            route('client.services.show', $service),
            route('client.invoices.index'),
            route('client.invoices.show', $invoice),
            route('client.tickets.index'),
            route('client.tickets.create'),
            route('client.tickets.show', $ticket),
            route('client.account.edit'),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('client.invoices.pdf', $invoice))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_clients_cannot_see_other_clients_records(): void
    {
        $other = Client::factory()->create();
        $service = Service::factory()->create(['client_id' => $other->id]);
        $invoice = Invoice::factory()->create(['client_id' => $other->id]);
        $ticket = Ticket::factory()->create(['client_id' => $other->id]);

        $this->actingAs(Client::factory()->create(), 'web');

        $this->get(route('client.services.show', $service))->assertNotFound();
        $this->get(route('client.invoices.show', $invoice))->assertNotFound();
        $this->get(route('client.invoices.pdf', $invoice))->assertNotFound();
        $this->post(route('client.invoices.pay', $invoice), ['gateway' => 'banktransfer'])->assertNotFound();
        $this->get(route('client.tickets.show', $ticket))->assertNotFound();
        $this->post(route('client.tickets.reply', $ticket), ['message' => 'hi'])->assertNotFound();
    }

    public function test_bank_transfer_shows_the_instructions_with_the_invoice_number(): void
    {
        $this->enableGateway('banktransfer', ['instructions' => "Pay to IBAN DE00 1234.\nReference: {invoice}"]);
        $client = Client::factory()->create();
        $invoice = Invoice::factory()->create(['client_id' => $client->id, 'number' => 'INV-0077']);

        $this->actingAs($client, 'web')
            ->followingRedirects()
            ->post(route('client.invoices.pay', $invoice), ['gateway' => 'banktransfer'])
            ->assertOk()
            ->assertSee('IBAN DE00 1234')
            ->assertSee('Reference: INV-0077');
    }

    public function test_a_ticket_conversation_between_client_and_staff(): void
    {
        Mail::fake();
        $client = Client::factory()->create();
        $department = TicketDepartment::query()->firstOrFail();

        $this->actingAs($client, 'web')->post(route('client.tickets.store'), [
            'department_id' => $department->id,
            'priority' => 'high',
            'subject' => 'SSL not working',
            'message' => 'My site shows not secure.',
        ])->assertRedirect();

        $ticket = Ticket::query()->firstOrFail();
        $this->assertSame(TicketStatus::Open, $ticket->status);
        Mail::assertSent(TemplatedMessage::class, fn ($mail) => $mail->hasTo($client->email));
        Mail::assertSent(TemplatedMessage::class, fn ($mail) => $mail->hasTo('billing@example.com'));

        $this->actingAs(Admin::factory()->create(), 'admin')
            ->post(route('admin.tickets.reply', $ticket), ['message' => 'Fixed it for you.', 'status' => 'answered'])
            ->assertRedirect(route('admin.tickets.show', $ticket));

        $this->assertSame(TicketStatus::Answered, $ticket->fresh()->status);

        $this->actingAs($client, 'web')
            ->post(route('client.tickets.reply', $ticket), ['message' => 'Thanks!'])
            ->assertRedirect();

        $this->assertSame(TicketStatus::CustomerReply, $ticket->fresh()->status);
        $this->assertCount(3, $ticket->replies);
        $this->get(route('client.tickets.show', $ticket))->assertSee('Fixed it for you.');
    }

    public function test_closed_client_accounts_are_signed_out(): void
    {
        $this->actingAs(Client::factory()->closed()->create(), 'web');

        $this->get(route('client.dashboard'))->assertRedirect(route('client.login'));
    }

    public function test_email_placeholders_are_replaced_but_code_is_not_run(): void
    {
        $text = TemplateMailer::render('Hi {{ client.first_name }} {{ unknown.key }} {{ client }}', [
            'client' => ['first_name' => '<b>Raz</b>'],
        ]);

        $this->assertSame('Hi <b>Raz</b>  ', $text);
    }
}
