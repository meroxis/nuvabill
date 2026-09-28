<?php

namespace Tests\Feature;

use App\Automations\Runner;
use App\Automations\Templates;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\TicketPriority;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\Invoice;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AutomationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_make_a_late_fee_automation_from_the_template_and_it_runs_its_steps(): void
    {
        Mail::fake();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));

        $this->get(route('admin.automations.index'))->assertOk()->assertSee('Late fee after 7 days');
        $this->get(route('admin.automations.create', ['template' => 'late-fee']))->assertOk()->assertSee('Late fee after 7 days');

        $template = Templates::find('late-fee');
        $this->post(route('admin.automations.store'), [
            'name' => $template['name'],
            'template' => 'late-fee',
            'definition' => json_encode(['trigger' => $template['trigger'], 'days' => $template['days'], 'conditions' => $template['conditions'], 'steps' => $template['steps']]),
            'activate' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $automation = Automation::query()->sole();
        $this->assertTrue($automation->is_active);

        $client = Client::factory()->create(['first_name' => 'Raz']);
        $invoice = $this->invoice($client, 5000, 9);
        $vip = $this->invoice(Client::factory()->create(['tags' => ['VIP']]), 5000, 9);

        $this->artisan('nuvabill:automations', ['--scan' => true])->assertSuccessful();

        $invoice->refresh();
        $this->assertSame(5250, $invoice->total);
        $this->assertTrue($invoice->items()->where('type', 'late_fee')->where('amount', 250)->exists());
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo($client->email) && $mail->subjectLine === 'Late fee added to invoice '.$invoice->number);

        $run = AutomationRun::query()->where('subject_id', $invoice->id)->sole();
        $this->assertSame(AutomationRun::WAITING, $run->status);
        $this->assertSame(5000, $vip->refresh()->total, 'VIP clients get no fee.');
        $this->assertSame(1, AutomationRun::query()->count());

        // A second check the same day starts nothing new.
        $this->artisan('nuvabill:automations', ['--scan' => true])->assertSuccessful();
        $this->assertSame(1, AutomationRun::query()->count());

        // After the wait, the invoice is still unpaid: the last reminder goes out.
        $this->travel(8)->days();
        $this->artisan('nuvabill:automations')->assertSuccessful();

        $this->assertSame(AutomationRun::DONE, $run->refresh()->status);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Last reminder: invoice '.$invoice->number);
        $this->get(route('admin.automations.runs'))->assertOk()->assertSee('Done');
    }

    public function test_a_waiting_run_stops_when_the_invoice_is_paid(): void
    {
        Mail::fake();
        $automation = $this->automation('invoice.overdue', 3, [
            ['type' => 'wait', 'config' => ['amount' => '2', 'unit' => 'days']],
            ['type' => 'send_email', 'config' => ['subject' => 'Still unpaid', 'body' => 'Please pay.']],
        ]);
        $invoice = $this->invoice(Client::factory()->create(), 2000, 4);

        app(Runner::class)->scan(today()->toImmutable());
        $run = AutomationRun::query()->sole();
        $this->assertSame(AutomationRun::WAITING, $run->status);

        app(PaymentRecorder::class)->record($invoice, 2000, 'banktransfer', 'REF-1');
        $this->assertSame(InvoiceStatus::Paid, $invoice->refresh()->status);

        $this->travel(3)->days();
        app(Runner::class)->resumeDue();

        $this->assertSame(AutomationRun::STOPPED, $run->refresh()->status);
        Mail::assertNotSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Still unpaid');
        $this->assertSame($automation->id, $run->automation_id);
    }

    public function test_vip_tickets_are_assigned_and_get_high_priority_right_away(): void
    {
        $staff = Admin::factory()->create(['name' => 'Mer Las']);
        $this->automation('ticket.opened', null, [
            ['type' => 'assign_ticket', 'config' => ['admin' => (string) $staff->id]],
            ['type' => 'ticket_priority', 'config' => ['priority' => 'high']],
        ], [['field' => 'client.tag', 'operator' => 'has', 'value' => 'vip']]);

        $department = TicketDepartment::factory()->create();
        $vip = app(TicketDesk::class)->open(Client::factory()->create(['tags' => ['VIP']]), $department, 'Help', 'My site is down');
        $other = app(TicketDesk::class)->open(Client::factory()->create(), $department, 'Question', 'Hello');

        $vip = $vip->fresh();
        $this->assertSame($staff->id, $vip->assigned_admin_id);
        $this->assertSame(TicketPriority::High, $vip->priority);
        $this->assertNull($other->fresh()->assigned_admin_id);

        $this->signInAdmin($staff);
        $this->get(route('admin.tickets.show', $vip))->assertOk()->assertSee('Assigned to');
        $this->post(route('admin.tickets.assign', $vip), ['admin' => ''])->assertSessionHas('status');
        $this->assertNull($vip->fresh()->assigned_admin_id);
    }

    public function test_try_it_shows_what_would_happen_and_changes_nothing(): void
    {
        $this->signInAdmin();
        $automation = $this->automation('invoice.overdue', 7, Templates::find('late-fee')['steps']);
        $invoice = $this->invoice(Client::factory()->create(), 4000, 8);

        $this->post(route('admin.automations.test', $automation), ['subject' => $invoice->number])
            ->assertRedirect()
            ->assertSessionHas('preview', fn (array $preview): bool => $preview['matches'] && str_contains($preview['lines'][0], 'Would add $2.00'));

        $this->assertSame(4000, $invoice->refresh()->total);
        $this->assertSame(0, AutomationRun::query()->count());
        $this->post(route('admin.automations.test', $automation), ['subject' => 'INV-NOPE'])->assertSessionHas('error');
    }

    public function test_the_builder_refuses_steps_that_do_not_fit_the_trigger(): void
    {
        $this->signInAdmin();

        $this->post(route('admin.automations.store'), [
            'name' => 'Broken',
            'definition' => json_encode(['trigger' => 'client.registered', 'steps' => [['type' => 'add_fee', 'config' => []]]]),
        ])->assertSessionHasErrors('definition');

        $this->post(route('admin.automations.store'), [
            'name' => 'No subject',
            'definition' => json_encode(['trigger' => 'client.registered', 'steps' => [['type' => 'send_email', 'config' => ['subject' => '', 'body' => 'Hi']]]]),
        ])->assertSessionHasErrors('definition');

        $this->assertSame(0, Automation::query()->count());
    }

    public function test_a_web_address_step_sends_the_details_and_refuses_local_addresses(): void
    {
        Http::fake(['hooks.example.test/*' => Http::response(['ok' => true])]);
        $this->automation('client.registered', null, [['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/nuvabill']]]);
        $local = $this->automation('client.registered', null, [['type' => 'webhook', 'config' => ['url' => 'https://127.0.0.1/internal']]]);

        $this->post(route('client.register'), [
            'first_name' => 'Raz',
            'last_name' => 'Las',
            'email' => 'raz@example-host.test',
            'country' => 'IQ',
            'password' => 'secret-pass-1',
            'password_confirmation' => 'secret-pass-1',
        ])->assertRedirect();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://hooks.example.test/nuvabill'
            && $request['trigger'] === 'client.registered' && $request['client']['email'] === 'raz@example-host.test');
        $this->assertSame(AutomationRun::FAILED, AutomationRun::query()->where('automation_id', $local->id)->sole()->status);
    }

    public function test_staff_without_the_right_cannot_open_automations(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create());

        $this->get(route('admin.automations.index'))->assertForbidden();
    }

    public function test_staff_tag_clients(): void
    {
        $this->signInAdmin();
        $client = Client::factory()->create();

        $this->put(route('admin.clients.update', $client), [
            'first_name' => $client->first_name,
            'last_name' => $client->last_name,
            'email' => $client->email,
            'status' => 'active',
            'tags' => 'VIP, reseller, vip ,  ',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['VIP', 'reseller'], $client->refresh()->tagList());
        $this->get(route('admin.clients.show', $client))->assertSee('reseller');
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  list<array<string, mixed>>  $conditions
     */
    private function automation(string $trigger, ?int $days, array $steps, array $conditions = []): Automation
    {
        return Automation::query()->create([
            'name' => 'Test '.$trigger,
            'trigger' => $trigger,
            'trigger_days' => $days,
            'conditions' => $conditions,
            'steps' => $steps,
            'is_active' => true,
        ]);
    }

    private function invoice(Client $client, int $amount, int $daysOverdue): Invoice
    {
        return app(InvoiceManager::class)->create($client, [['description' => 'Web hosting', 'amount' => $amount]], today()->subDays($daysOverdue));
    }
}
