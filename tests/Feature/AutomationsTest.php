<?php

namespace Tests\Feature;

use App\Automations\Runner;
use App\Automations\Steps\CallWebhook;
use App\Automations\Templates;
use App\Billing\InvoiceManager;
use App\Billing\PaymentRecorder;
use App\Enums\InvoiceStatus;
use App\Enums\TicketPriority;
use App\Events\ClientRegistered;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\TicketDepartment;
use App\Support\TicketDesk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Throwable;

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

    public function test_a_domain_expiring_automation_runs_again_after_the_domain_is_renewed(): void
    {
        Mail::fake();
        $this->automation('domain.expiring', 30, [['type' => 'send_email', 'config' => ['subject' => 'Renew {{ domain.name }}', 'body' => 'Please renew.']]]);
        $domain = Domain::factory()->for(Client::factory()->create(['first_name' => 'Raz']))->expiringOn(today()->addDays(20))->create();

        app(Runner::class)->scan(today()->toImmutable());
        app(Runner::class)->scan(today()->toImmutable());

        $this->assertSame(1, AutomationRun::query()->count());
        Mail::assertSent(TemplatedMessage::class, 1);

        // Renewed for a year: the same domain expires again next year, and gets its reminder again.
        $domain->update(['expires_at' => $domain->expires_at->addYear()]);
        $this->travel(1)->years();
        app(Runner::class)->scan(today()->toImmutable());

        $this->assertSame(2, AutomationRun::query()->count());
        Mail::assertSent(TemplatedMessage::class, 2);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Renew '.$domain->name);
    }

    public function test_runs_started_before_occasions_were_in_the_key_are_not_started_again(): void
    {
        $automation = $this->automation('invoice.overdue', 7, Templates::find('late-fee')['steps']);
        $invoice = $this->invoice(Client::factory()->create(['first_name' => 'Raz']), 5000, 9);
        // Started yesterday by the earlier version, whose key had no due date in it.
        AutomationRun::query()->forceCreate([
            'automation_id' => $automation->id,
            'subject_type' => $invoice->getMorphClass(),
            'subject_id' => $invoice->id,
            'status' => AutomationRun::WAITING,
            'step' => 2,
            'resume_at' => now()->addDays(6),
            'dedupe_key' => 'a'.$automation->id.':'.$invoice->getMorphClass().':'.$invoice->id.':d7',
            'created_at' => now()->subDay(),
        ]);

        $this->assertSame(0, app(Runner::class)->scan(today()->toImmutable()));

        $this->assertSame(1, AutomationRun::query()->count());
        $this->assertSame(5000, $invoice->refresh()->total, 'No second late fee.');
    }

    public function test_the_daily_check_starts_every_due_subject_not_only_the_first_500(): void
    {
        $automation = $this->automation('invoice.overdue', 7, [['type' => 'wait', 'config' => ['amount' => '1', 'unit' => 'days']]]);
        Invoice::factory()->count(510)->overdue(9)->for(Client::factory()->create(['first_name' => 'Raz']))->create();

        $this->assertSame(510, app(Runner::class)->scan(today()->toImmutable()));
        $this->assertSame(510, AutomationRun::query()->where('automation_id', $automation->id)->count());

        $this->travel(1)->days();
        $this->assertSame(0, app(Runner::class)->scan(today()->toImmutable()), 'Nothing starts twice.');
    }

    public function test_changing_the_steps_stops_waiting_runs_instead_of_repeating_a_step(): void
    {
        Mail::fake();
        $credit = ['type' => 'add_credit', 'config' => ['amount' => '5', 'description' => 'Welcome gift']];
        $wait = ['type' => 'wait', 'config' => ['amount' => '3', 'unit' => 'days']];
        $email = ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'body' => 'Hello.']];
        $automation = $this->automation('client.registered', null, [$credit, $wait, $email]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);

        ClientRegistered::dispatch($client);

        $run = AutomationRun::query()->sole();
        $this->assertSame([AutomationRun::WAITING, 2], [$run->status, $run->step]);
        $this->assertSame(500, $client->refresh()->credit);

        $this->signInAdmin();
        $this->put(route('admin.automations.update', $automation), [
            'name' => $automation->name,
            'definition' => json_encode(['trigger' => 'client.registered', 'steps' => [
                ['type' => 'wait', 'config' => ['amount' => '1', 'unit' => 'hours']], $email, $credit, $wait, $email,
            ]]),
            'activate' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->travel(4)->days();
        app(Runner::class)->resumeDue();

        $this->assertSame(500, $client->refresh()->credit, 'The credit is not added a second time.');
        $this->assertSame(AutomationRun::STOPPED, $run->refresh()->status);
        $this->assertStringContainsString('steps of the automation were changed', $run->lastLog());
        Mail::assertNothingSent();
    }

    public function test_the_update_protects_runs_that_were_already_waiting_from_step_changes(): void
    {
        Mail::fake();
        $wait = ['type' => 'wait', 'config' => ['amount' => '3', 'unit' => 'days']];
        $reminder = ['type' => 'send_email', 'config' => ['subject' => 'Reminder', 'body' => 'Hello.']];
        $welcome = ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'body' => 'Hello.']];
        $credit = ['type' => 'add_credit', 'config' => ['amount' => '5', 'description' => 'Welcome gift']];
        $changed = $this->automation('client.registered', null, [$wait, $reminder, $wait, $credit]);
        $unchanged = $this->automation('client.registered', null, [$wait, $welcome]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las']);

        ClientRegistered::dispatch($client);

        // Runs started before the update have no fingerprint of their steps.
        $finished = AutomationRun::query()->forceCreate([
            'automation_id' => $changed->id, 'subject_type' => $client->getMorphClass(), 'subject_id' => $client->id, 'client_id' => $client->id,
            'status' => AutomationRun::DONE, 'step' => 4, 'dedupe_key' => 'older-run', 'log' => [],
        ]);
        AutomationRun::query()->update(['steps_hash' => null]);
        $this->assertSame(2, AutomationRun::query()->where('status', AutomationRun::WAITING)->where('step', 1)->count());

        (require database_path('migrations/2027_07_02_000001_automations_chat_ai_add_steps_hash_to_automation_runs.php'))->up();

        $run = AutomationRun::query()->where('automation_id', $changed->id)->where('status', AutomationRun::WAITING)->sole();
        $this->assertSame(Runner::hashSteps([$wait, $reminder, $wait, $credit]), $run->steps_hash);
        $this->assertNull($finished->refresh()->steps_hash, 'Finished runs are left as they are.');

        // Staff take the reminder out while the run waits: step 2 is now the credit.
        $changed->update(['steps' => [$wait, $credit]]);

        $this->travel(4)->days();
        app(Runner::class)->resumeDue();

        $this->assertSame(AutomationRun::STOPPED, $run->refresh()->status);
        $this->assertStringContainsString('steps of the automation were changed', $run->lastLog());
        $this->assertSame(0, $client->refresh()->credit);
        $this->assertSame(AutomationRun::DONE, AutomationRun::query()->where('automation_id', $unchanged->id)->sole()->status, 'A run whose steps did not change goes on.');
        Mail::assertSent(TemplatedMessage::class, 1);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->subjectLine === 'Welcome');
    }

    public function test_a_web_address_step_refuses_shared_and_special_address_ranges(): void
    {
        foreach (['https://100.64.0.1/x', 'https://100.100.100.200/x', 'https://198.18.0.1/x', 'https://192.0.0.1/x', 'https://10.0.0.5/x', 'http://1.1.1.1/x'] as $url) {
            $this->assertFalse(CallWebhook::isAllowed($url), $url);
        }

        $this->assertTrue(CallWebhook::isAllowed('https://1.1.1.1/x'));

        // A name that points to the internet and to this server is refused.
        CallWebhook::$resolveUsing = fn (string $host): array => ['1.1.1.1', '127.0.0.1'];

        try {
            $this->assertFalse(CallWebhook::isAllowed('https://hooks.example.test/x'));
        } finally {
            CallWebhook::$resolveUsing = null;
        }
    }

    public function test_a_web_address_step_connects_to_the_address_it_checked(): void
    {
        CallWebhook::$resolveUsing = fn (string $host): array => $host === 'hooks.example.test' ? ['1.1.1.1'] : [];
        $seen = null;
        Http::fake(function (Request $request, array $options) use (&$seen) {
            $seen = $options;

            return Http::response(['ok' => true]);
        });
        $this->automation('client.registered', null, [['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test:8443/nuvabill']]]);

        try {
            ClientRegistered::dispatch(Client::factory()->create(['first_name' => 'Raz']));
        } finally {
            CallWebhook::$resolveUsing = null;
        }

        $this->assertSame(AutomationRun::DONE, AutomationRun::query()->sole()->status);
        $this->assertSame('v4', $seen['force_ip_resolve'] ?? null);
        $this->assertSame(['hooks.example.test:8443:1.1.1.1'], $seen['curl'][CURLOPT_RESOLVE] ?? null);
    }

    public function test_a_web_address_that_cannot_be_reached_does_not_show_why_on_the_run_page(): void
    {
        Http::fake(Http::failedConnection('cURL error 7: Failed to connect to hooks.example.test port 8080: Connection refused'));
        $this->automation('client.registered', null, [['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test:8080/nuvabill']]]);

        ClientRegistered::dispatch(Client::factory()->create(['first_name' => 'Raz']));

        $run = AutomationRun::query()->sole();
        $this->assertSame(AutomationRun::FAILED, $run->status);
        $this->assertSame('The address could not be reached.', $run->error);
        $this->assertStringNotContainsString('cURL', $run->lastLog());
    }

    public function test_a_web_address_that_cannot_be_reached_is_logged_without_its_secret_path(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });
        // Like a real failure, the message ends with ' for <the full address>'.
        Http::fake(Http::failedConnection());
        $this->automation('client.registered', null, [['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test:8443/hooks/catch/123456/SECRETTOKEN?key=QUERYSECRET']]]);

        ClientRegistered::dispatch(Client::factory()->create(['first_name' => 'Raz']));

        $this->assertSame(AutomationRun::FAILED, AutomationRun::query()->sole()->status);
        $this->assertNotEmpty($logged);

        foreach ($logged as $event) {
            $text = $event->message.' '.json_encode(array_map(fn ($value) => $value instanceof Throwable ? $value->getMessage() : $value, $event->context));
            $this->assertStringNotContainsString('SECRETTOKEN', $text);
            $this->assertStringNotContainsString('QUERYSECRET', $text);
            $this->assertStringNotContainsString('/hooks/catch', $text);
        }

        $warning = collect($logged)->first(fn (MessageLogged $event): bool => $event->message === 'Automation web address could not be reached.');
        $this->assertNotNull($warning, 'The failure is still written to the error log.');
        $this->assertSame('hooks.example.test', $warning->context['host']);
        $this->assertStringContainsString('Could not resolve host: hooks.example.test', $warning->context['error']);
        $this->assertStringEndsWith('for hooks.example.test.', $warning->context['error']);
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
