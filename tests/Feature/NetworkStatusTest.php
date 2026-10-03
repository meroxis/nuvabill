<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Extensions\ExtensionManager;
use App\Mail\TemplateMailer;
use App\Models\Admin;
use App\Models\Client;
use App\Models\NetworkIncident;
use App\Models\Server;
use App\Models\Service;
use App\Support\ServerMonitor;
use App\Support\Settings;
use Database\Seeders\DefaultDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The network status page: server checks, staff alerts, issues and maintenance with updates, and
 * notices for the clients they touch.
 */
class NetworkStatusTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var list<int|null>
     */
    private array $answers = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DefaultDataSeeder::class);
        app(Settings::class)->set('company.email', 'staff@example.test');

        // Each check takes the next answer: milliseconds, or null for no answer.
        $test = $this;
        $this->app->instance(ServerMonitor::class, new class(app(ExtensionManager::class), app(TemplateMailer::class), $test) extends ServerMonitor
        {
            public function __construct(ExtensionManager $extensions, TemplateMailer $mailer, private NetworkStatusTest $test)
            {
                parent::__construct($extensions, $mailer);
            }

            protected function probe(Server $server): ?int
            {
                return $this->test->nextAnswer();
            }
        });
    }

    public function nextAnswer(): ?int
    {
        return array_shift($this->answers);
    }

    public function test_a_server_is_down_after_two_failed_checks_and_staff_get_an_email_each_way(): void
    {
        Mail::fake();
        $server = Server::factory()->create(['name' => 'Server 1', 'status_public' => true]);
        $this->answers = [40, null, null, 35];

        $this->artisan('nuvabill:server-status')->assertSuccessful();
        $this->assertTrue($server->fresh()->status_up);

        $this->artisan('nuvabill:server-status');
        $this->assertTrue($server->fresh()->status_up, 'One failed check is not enough.');
        Mail::assertNothingOutgoing();

        $this->artisan('nuvabill:server-status');
        $this->assertFalse($server->fresh()->status_up);
        $this->get(route('network.status'))->assertOk()->assertSee('Some services are down')->assertSee('Server 1');

        $this->artisan('nuvabill:server-status');
        $this->assertTrue($server->fresh()->status_up);
        $this->assertSame(4, $server->checks()->count());
        $this->assertSame(50.0, Server::uptime([$server->id])[$server->id]);
        Mail::assertSentCount(2);
    }

    public function test_the_status_page_shows_only_public_servers_by_name(): void
    {
        Server::factory()->create(['name' => 'Internal box', 'hostname' => 'secret.example.test', 'status_public' => false]);
        Server::factory()->create(['name' => 'web7', 'status_name' => 'Shared hosting', 'hostname' => 'web7.example.test', 'status_public' => true, 'status_up' => true]);

        $this->get(route('network.status'))
            ->assertOk()
            ->assertSee('All services are running')
            ->assertSee('Shared hosting')
            ->assertDontSee('Internal box')
            ->assertDontSee('secret.example.test')
            ->assertDontSee('web7.example.test');
    }

    public function test_an_incident_on_the_status_page_names_only_servers_shown_there(): void
    {
        $hidden = Server::factory()->create(['name' => 'Internal box', 'status_public' => false]);
        $shown = Server::factory()->create(['name' => 'web7', 'status_name' => 'Shared hosting', 'status_public' => true, 'status_up' => true]);
        $inactive = Server::factory()->create(['name' => 'Old box', 'status_public' => true, 'is_active' => false]);
        $incident = NetworkIncident::factory()->create(['title' => 'Email is slow', 'server_ids' => [$hidden->id, $shown->id, $inactive->id]]);
        $client = Client::factory()->create();
        Service::factory()->for($client)->create(['server_id' => $hidden->id]);

        $this->get(route('network.status'))->assertOk()->assertSee('Email is slow')->assertSee('Shared hosting')
            ->assertDontSee('Internal box')->assertDontSee('Old box')->assertDontSee('web7');

        // Resolved lately, too.
        $incident->update(['status' => IncidentStatus::Resolved, 'resolved_at' => now()->subHour()]);
        $this->get(route('network.status'))->assertOk()->assertSee('Email is slow')->assertSee('Shared hosting')
            ->assertDontSee('Internal box')->assertDontSee('Old box');

        // Clients on the hidden server are still told.
        $incident->update(['status' => IncidentStatus::Investigating, 'resolved_at' => null]);
        $this->actingAs($client, 'web')->get(route('client.dashboard'))->assertOk()->assertSee('Email is slow');
    }

    public function test_staff_post_an_issue_with_updates_and_affected_clients_see_it(): void
    {
        $admin = Admin::factory()->withPermissions(['status.manage'])->create();
        $server = Server::factory()->create();
        $other = Server::factory()->create();
        $affected = Client::factory()->create();
        $untouched = Client::factory()->create();
        Service::factory()->for($affected)->create(['server_id' => $server->id]);
        Service::factory()->for($untouched)->create(['server_id' => $other->id]);

        $this->actingAs($admin, 'admin')->post(route('admin.network.incidents.store'), [
            'kind' => 'issue',
            'title' => 'Email is slow on server 1',
            'impact' => 'minor',
            'status' => 'investigating',
            'server_ids' => [$server->id],
            'message' => 'We are looking into it.',
        ])->assertRedirect();
        $incident = NetworkIncident::query()->firstOrFail();

        $this->get(route('network.status'))->assertOk()->assertSee('Some services have problems')->assertSee('We are looking into it.');
        $this->actingAs($affected, 'web')->get(route('client.dashboard'))->assertOk()->assertSee('Email is slow on server 1');
        $this->actingAs($untouched, 'web')->get(route('client.dashboard'))->assertOk()->assertDontSee('Email is slow on server 1');

        // A status from planned maintenance does not fit an issue.
        $this->actingAs($admin, 'admin')->post(route('admin.network.incidents.updates.store', $incident), ['status' => 'scheduled', 'message' => 'x'])
            ->assertSessionHasErrors('status');

        $this->post(route('admin.network.incidents.updates.store', $incident), ['status' => 'resolved', 'message' => 'Fixed.'])->assertSessionHas('status');
        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(2, $incident->updates()->count());

        $this->get(route('network.status'))->assertOk()->assertSee('All services are running')->assertSee('Resolved lately');
        $this->actingAs($affected, 'web')->get(route('client.dashboard'))->assertOk()->assertDontSee('Email is slow on server 1');
    }

    public function test_maintenance_shows_on_the_dashboard_only_in_the_week_before(): void
    {
        $client = Client::factory()->create();
        NetworkIncident::factory()->maintenance()->create(['title' => 'Disk upgrade', 'starts_at' => now()->addDays(10)]);

        $this->actingAs($client, 'web')->get(route('client.dashboard'))->assertOk()->assertDontSee('Disk upgrade');

        $this->travel(4)->days();
        $this->actingAs($client, 'web')->get(route('client.dashboard'))->assertOk()->assertSee('Disk upgrade');
        $this->get(route('network.status'))->assertOk()->assertSee('Planned maintenance')->assertSee('All services are running');
    }

    public function test_only_staff_with_the_right_post_notes_and_the_page_can_be_switched_off(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['support.manage'])->create(), 'admin')
            ->get(route('admin.network.index'))
            ->assertForbidden();

        app(Settings::class)->set('status.enabled', false);
        $this->get(route('network.status'))->assertNotFound();
        $this->get('/serverstatus.php')->assertRedirect('/network-status');
    }
}
