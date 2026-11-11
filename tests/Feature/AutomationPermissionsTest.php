<?php

namespace Tests\Feature;

use App\Automations\Context;
use App\Automations\Registry;
use App\Automations\Steps\Step;
use App\Models\Admin;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Client;
use App\Models\TicketDepartment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every automation step stands for a staff permission. Staff save, switch on and try only the
 * steps they may do by hand; automations that are on keep running whoever made them.
 */
class AutomationPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private const CREDIT = ['type' => 'add_credit', 'config' => ['amount' => '5', 'description' => 'Thank you']];

    private const WAIT = ['type' => 'wait', 'config' => ['amount' => '1', 'unit' => 'days']];

    public function test_staff_with_only_the_automations_permission_cannot_save_steps_that_need_more(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage'])->create(['name' => 'Raz']));

        $refused = [
            'client.registered' => [
                self::CREDIT,
                ['type' => 'send_email', 'config' => ['subject' => 'Hello', 'body' => 'Hello']],
                ['type' => 'email_staff', 'config' => ['to' => 'company', 'subject' => 'New client', 'body' => '{{ client.email }}']],
                ['type' => 'tag_client', 'config' => ['tag' => 'VIP']],
                ['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/in']],
            ],
            'service.activated' => [
                ['type' => 'suspend_service', 'config' => ['reason' => 'Check first']],
                ['type' => 'unsuspend_service', 'config' => []],
            ],
            'invoice.paid' => [
                ['type' => 'add_fee', 'config' => ['kind' => 'fixed', 'amount' => '1', 'description' => 'Fee', 'once' => true]],
            ],
            'ticket.opened' => [
                ['type' => 'ticket_priority', 'config' => ['priority' => 'high']],
            ],
        ];

        foreach ($refused as $trigger => $steps) {
            foreach ($steps as $step) {
                $this->post(route('admin.automations.store'), $this->form($trigger, [$step], ['activate' => '1']))
                    ->assertSessionHasErrors('definition');
            }
        }

        $this->assertSame(0, Automation::query()->count());

        // Steps that only pause or check need nothing more.
        $this->post(route('admin.automations.store'), $this->form('client.registered', [self::WAIT]))->assertSessionHasNoErrors();
        $this->assertSame(1, Automation::query()->count());
    }

    public function test_staff_save_the_steps_their_role_allows(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'billing.manage', 'clients.manage'])->create(['name' => 'Raz']));

        $this->post(route('admin.automations.store'), $this->form('client.registered', [
            self::CREDIT,
            ['type' => 'send_email', 'config' => ['subject' => 'Welcome', 'body' => 'Hello {{ client.first_name }}']],
            ['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/in']],
        ], ['activate' => '1']))->assertSessionHasNoErrors();

        $this->assertTrue(Automation::query()->sole()->is_active);

        // Suspending services is not theirs, so that step is still refused.
        $this->post(route('admin.automations.store'), $this->form('service.activated', [['type' => 'suspend_service', 'config' => ['reason' => 'Check first']]]))
            ->assertSessionHasErrors('definition');
    }

    public function test_steps_that_send_details_on_need_the_permission_to_see_them(): void
    {
        $webhook = ['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/in']];
        $emailStaff = ['type' => 'email_staff', 'config' => ['to' => 'company', 'subject' => 'Paid', 'body' => '{{ invoice.total }}']];

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'clients.view'])->create(['name' => 'Raz']));
        $this->post(route('admin.automations.store'), $this->form('invoice.paid', [$webhook]))->assertSessionHasErrors('definition');
        $this->post(route('admin.automations.store'), $this->form('invoice.paid', [$emailStaff]))->assertSessionHasErrors('definition');
        $this->post(route('admin.automations.store'), $this->form('client.registered', [$webhook]))->assertSessionHasNoErrors();

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'clients.view', 'billing.view'])->create(['name' => 'Las']));
        $this->post(route('admin.automations.store'), $this->form('invoice.paid', [$webhook, $emailStaff]))->assertSessionHasNoErrors();

        $this->assertSame(2, Automation::query()->count());
    }

    public function test_a_ticket_or_a_tag_about_a_record_needs_the_permission_to_see_it(): void
    {
        $ticket = ['type' => 'open_ticket', 'config' => [
            'department' => (string) TicketDepartment::query()->firstOrFail()->id, 'subject' => 'Paid', 'message' => '{{ invoice.total }}', 'priority' => 'medium',
        ]];
        $tag = ['type' => 'tag_client', 'config' => ['tag' => 'Paid']];
        $untag = ['type' => 'untag_client', 'config' => ['tag' => 'Paid']];

        // Answering tickets and editing clients alone do not show invoices, services or new clients.
        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'support.manage', 'clients.manage'])->create(['name' => 'Raz']));

        foreach ([['invoice.paid', $ticket], ['invoice.paid', $tag], ['invoice.paid', $untag], ['service.suspended', $ticket], ['service.suspended', $tag]] as [$trigger, $step]) {
            $this->post(route('admin.automations.store'), $this->form($trigger, [$step], ['activate' => '1']))->assertSessionHasErrors('definition');
        }

        $this->assertSame(0, Automation::query()->count());

        // What they may see is theirs to act on.
        $this->post(route('admin.automations.store'), $this->form('ticket.opened', [$ticket, $tag]))->assertSessionHasNoErrors();
        $this->post(route('admin.automations.store'), $this->form('client.registered', [$ticket, $tag]))->assertSessionHasNoErrors();

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'support.manage'])->create(['name' => 'Mer']));
        $this->post(route('admin.automations.store'), $this->form('client.registered', [$ticket]))->assertSessionHasErrors('definition');

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'support.manage', 'clients.manage', 'billing.view'])->create(['name' => 'Las']));
        $this->post(route('admin.automations.store'), $this->form('invoice.paid', [$ticket, $tag]))->assertSessionHasNoErrors();

        $this->assertSame(3, Automation::query()->count());
    }

    public function test_staff_cannot_change_or_switch_on_an_automation_with_steps_beyond_their_role(): void
    {
        $automation = $this->automation('client.registered', [self::CREDIT], active: false);
        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage'])->create(['name' => 'Raz']));

        $this->put(route('admin.automations.update', $automation), $this->form('ticket.opened', [self::CREDIT], ['activate' => '1']))
            ->assertSessionHasErrors('definition');
        $this->post(route('admin.automations.toggle', $automation))->assertSessionHas('error');

        $automation->refresh();
        $this->assertFalse($automation->is_active);
        $this->assertSame('client.registered', $automation->trigger);

        // Without those steps it is theirs to save.
        $this->put(route('admin.automations.update', $automation), $this->form('client.registered', [self::WAIT], ['activate' => '1']))
            ->assertSessionHasNoErrors();
        $this->assertTrue($automation->fresh()->is_active);
    }

    public function test_automations_that_are_on_keep_running_and_can_always_be_switched_off(): void
    {
        $automation = $this->automation('client.registered', [self::CREDIT]);
        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage'])->create(['name' => 'Raz']));

        // Nobody is signed in when a run starts: the steps are not checked again.
        $client = Client::factory()->create(['first_name' => 'Las', 'credit' => 0]);
        app(Registry::class)->fire('client.registered', $client);

        $this->assertSame(AutomationRun::DONE, AutomationRun::query()->sole()->status);
        $this->assertSame(500, $client->fresh()->credit);

        $this->post(route('admin.automations.toggle', $automation))->assertSessionHas('status');
        $this->assertFalse($automation->fresh()->is_active);

        $this->post(route('admin.automations.toggle', $automation))->assertSessionHas('error');
        $this->assertFalse($automation->fresh()->is_active);
    }

    public function test_try_it_needs_the_permissions_of_the_record_and_the_steps(): void
    {
        $automation = $this->automation('client.registered', [self::CREDIT]);
        $client = Client::factory()->create(['first_name' => 'Las', 'email' => 'las@example.test']);

        foreach ([['automations.manage'], ['automations.manage', 'billing.manage'], ['automations.manage', 'clients.view']] as $permissions) {
            $this->signInAdmin(Admin::factory()->withPermissions($permissions)->create());

            $this->post(route('admin.automations.test', $automation), ['subject' => 'las@example.test'])
                ->assertSessionHas('error', 'You cannot try this automation. It needs permissions you do not have.')
                ->assertSessionMissing('preview');
            $this->get(route('admin.automations.edit', $automation))->assertOk()->assertDontSee('name="subject"', false);
        }

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'billing.manage', 'clients.view'])->create(['name' => 'Raz']));
        $this->get(route('admin.automations.edit', $automation))->assertOk()->assertSee('name="subject"', false);
        $this->post(route('admin.automations.test', $automation), ['subject' => 'las@example.test'])
            ->assertSessionHas('preview', fn (array $preview): bool => $preview['subject'] === $client->name);

        $this->assertSame(0, $client->fresh()->credit);
    }

    public function test_the_run_list_shows_what_a_run_was_about_only_to_staff_who_may_see_it(): void
    {
        $automation = $this->automation('client.registered', [self::WAIT]);
        app(Registry::class)->fire('client.registered', Client::factory()->create(['first_name' => 'Las', 'last_name' => 'Hidden-Name']));

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage'])->create(['name' => 'Raz']));

        foreach ([route('admin.automations.runs'), route('admin.automations.index'), route('admin.automations.edit', $automation)] as $page) {
            $this->get($page)->assertOk()->assertSee('A record your role cannot see')->assertDontSee('Hidden-Name');
        }

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'clients.view'])->create(['name' => 'Mer']));
        $this->get(route('admin.automations.runs'))->assertOk()->assertSee('Las Hidden-Name')->assertDontSee('A record your role cannot see');
    }

    public function test_the_editor_only_offers_steps_the_role_allows(): void
    {
        $staff = $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'support.manage'])->create(['name' => 'Raz']));
        $this->get(route('admin.automations.create'))->assertOk();
        $steps = app(Registry::class)->editorDefinitions($staff)['steps'];

        $this->assertContains('client', $steps['add_credit']['denied']);
        $this->assertContains('ticket', $steps['add_credit']['denied']);
        $this->assertContains('service', $steps['suspend_service']['denied']);
        $this->assertSame([], $steps['ticket_priority']['denied']);
        $this->assertSame([], $steps['wait']['denied']);
        $this->assertSame([], $steps['only_if']['denied']);
        // Sending the details of a ticket on needs both the ticket and the client to be theirs to see.
        $this->assertContains('ticket', $steps['webhook']['denied']);
        // A ticket is offered where the role may see what the trigger is about.
        $this->assertNotContains('ticket', $steps['open_ticket']['denied']);
        $this->assertContains('invoice', $steps['open_ticket']['denied']);
        $this->assertContains('client', $steps['open_ticket']['denied']);

        $owner = $this->signInAdmin(Admin::factory()->create(['name' => 'Mer']));
        $this->get(route('admin.automations.create'))->assertOk();
        $steps = app(Registry::class)->editorDefinitions($owner)['steps'];

        $this->assertSame([], array_merge(...array_column($steps, 'denied')));
    }

    public function test_a_step_from_an_add_on_that_names_no_permission_needs_the_settings_permission(): void
    {
        app(Registry::class)->addStep(new class extends Step
        {
            public function key(): string
            {
                return 'addon_ping';
            }

            public function label(): string
            {
                return 'Ping';
            }

            public function summary(array $config): string
            {
                return 'Ping';
            }

            public function preview(Context $context, array $config): string
            {
                return 'Would ping';
            }

            public function run(Context $context, array $config): string
            {
                return 'Pinged';
            }
        });
        $form = $this->form('client.registered', [['type' => 'addon_ping', 'config' => []]]);

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'billing.manage'])->create(['name' => 'Raz']));
        $this->post(route('admin.automations.store'), $form)->assertSessionHasErrors('definition');

        $this->signInAdmin(Admin::factory()->withPermissions(['automations.manage', 'settings.manage'])->create(['name' => 'Las']));
        $this->post(route('admin.automations.store'), $form)->assertSessionHasNoErrors();

        $this->assertSame(1, Automation::query()->count());
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function form(string $trigger, array $steps, array $extra = []): array
    {
        return $extra + ['name' => 'Test '.$trigger, 'definition' => json_encode(['trigger' => $trigger, 'days' => null, 'conditions' => [], 'steps' => $steps])];
    }

    /**
     * @param  list<array<string, mixed>>  $steps
     */
    private function automation(string $trigger, array $steps, bool $active = true): Automation
    {
        return Automation::query()->create(['name' => 'Test '.$trigger, 'trigger' => $trigger, 'conditions' => [], 'steps' => $steps, 'is_active' => $active]);
    }
}
