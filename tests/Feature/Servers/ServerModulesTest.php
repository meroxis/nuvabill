<?php

namespace Tests\Feature\Servers;

use App\Automation\DailyAutomation;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Jobs\ProvisionService;
use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ServerModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_virtualizor_creates_a_vps_and_remembers_its_id(): void
    {
        Http::fake(['vz.example.test:4085/*' => Http::response(['done' => 1, 'vs_info' => ['vpsid' => 77, 'uid' => '58', 'ips' => ['203.0.113.7']]])]);

        $service = $this->service('virtualizor', 'vz.example.test', ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '3'], ['status' => ServiceStatus::Pending, 'domain' => 'vps1.example.com']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('77', $service->module_data['vpsid']);
        $this->assertSame('58', $service->module_data['uid']);
        $this->assertSame('root', $service->username);
        // Without the new product options the request is the same as before.
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://vz.example.test:4085/index.php?act=addvs&')
            && str_contains($request->url(), 'adminapikey=TESTTOKEN123')
            && $request['virt'] === 'kvm'
            && $request['osid'] === 100
            && $request['plid'] === 3
            && $request['num_ips'] === 1
            && $request['node_select'] === 1
            && $request['hostname'] === 'vps1.example.com'
            && collect(array_keys($request->data()))->sort()->values()->all() === ['addvps', 'hostname', 'node_select', 'num_ips', 'osid', 'plid', 'rootpass', 'user_email', 'user_pass', 'virt']);
    }

    public function test_clients_manage_their_virtualizor_vps_inside_the_client_area(): void
    {
        // Each answer is only given to the exact query Virtualizor's API expects.
        Http::fake(function (Request $request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            unset($query['api'], $query['adminapikey'], $query['adminapipass']);

            return match ($query) {
                ['act' => 'ostemplates'] => Http::response(['ostemplates' => ['100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04'], '200' => ['osid' => 200, 'type' => 'openvz', 'name' => 'CentOS 7']]]),
                ['act' => 'vs', 'vs_status' => '77'] => Http::response(['status' => ['77' => ['status' => 1, 'used_cpu' => 12.5, 'used_ram' => 512, 'ram' => 2048, 'used_disk' => 8, 'disk' => 40, 'used_bandwidth' => 3, 'bandwidth' => 1000, 'net_in' => 2048, 'net_out' => 512]]]),
                ['act' => 'vs', 'action' => 'restart', 'vpsid' => '77'] => Http::response(['done' => true, 'done_msg' => 'Restarted', 'vsop' => ['action' => 'restart', 'id' => '77', 'status' => ['77' => 1]]]),
                ['act' => 'vs', 'search' => '1', 'vpsid' => '77', 'page' => '1', 'reslen' => '1'] => Http::response(['vs' => ['77' => ['vpsid' => '77', 'uid' => '58', 'hostname' => 'vps1.example.com', 'os_name' => 'Ubuntu 24.04', 'virt' => 'kvm', 'suspended' => '0', 'ips' => ['2' => '203.0.113.7', '3' => '2001:db8::7']]]]),
                ['act' => 'rebuild'] => Http::response(['done' => 1]),
                default => Http::response(['error' => ['Unexpected request']]),
            };
        });

        $service = $this->service('virtualizor', 'vz.example.test', ['virt' => 'kvm', 'os_id' => '100'], ['module_data' => ['vpsid' => '77'], 'username' => 'root']);
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee('Manage your server')
            ->assertSee('Running')
            ->assertSee('203.0.113.7')
            ->assertSee('2001:db8::7')
            ->assertSee('2.0 KB/s')
            ->assertSee('Ubuntu 24.04')
            ->assertSee('Show VNC details')
            ->assertDontSee('CentOS 7')
            ->assertDontSee('Open control panel')
            ->assertDontSee('Open Virtualizor panel')
            ->assertDontSee('Run the setup script of your plan again');
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://vz.example.test:4085/index.php?act=vs&vs_status=77&api=json&'));
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://vz.example.test:4085/index.php?act=vs&search=1&vpsid=77&page=1&reslen=1&api=json&'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'vs_status%5B'));

        $this->from(route('client.services.show', $service))
            ->post(route('client.services.panel', [$service, 'restart']))
            ->assertRedirect(route('client.services.show', $service))
            ->assertSessionHas('status', 'The VPS is restarting.')
            ->assertSessionHas('panel_result', ['pending' => 'restart', 'state' => 'running']);
        Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://vz.example.test:4085/index.php?act=vs&action=restart&vpsid=77&api=json&') && $request->method() === 'GET');

        $this->post(route('client.services.panel', [$service, 'reinstall']), ['os_id' => '200', 'password' => 'Secret12345'])
            ->assertSessionHas('error', 'Choose an operating system from the list.');
        $this->post(route('client.services.panel', [$service, 'reinstall']), ['os_id' => '100', 'password' => 'Secret12345'])
            ->assertSessionHas('status');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'act=rebuild') && $request['osid'] === '100' && $request['reos'] === 1);
        $this->assertSame('Secret12345', $service->fresh()->password);

        $this->post(route('client.services.panel', [$service, 'delete']))->assertSessionHas('error', 'This action is not available.');
    }

    public function test_clients_cannot_use_the_panel_of_another_client_or_a_suspended_service(): void
    {
        Http::fake();

        $service = $this->service('virtualizor', 'vz.example.test', ['virt' => 'kvm'], ['module_data' => ['vpsid' => '77']]);

        $this->actingAs(Client::factory()->create(), 'web')
            ->post(route('client.services.panel', [$service, 'restart']))
            ->assertNotFound();

        $service->update(['status' => ServiceStatus::Suspended]);
        $this->actingAs($service->client, 'web')
            ->post(route('client.services.panel', [$service, 'restart']))
            ->assertSessionHas('error', 'This action is not available.');

        Http::assertNothingSent();
    }

    public function test_a_panel_that_does_not_answer_still_shows_the_service_page(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $service = $this->service('virtualizor', 'vz.example.test', ['virt' => 'kvm'], ['module_data' => ['vpsid' => '77']]);

        $this->actingAs($service->client, 'web')
            ->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee('The server did not answer.');
    }

    public function test_plesk_creates_a_customer_and_subscription_and_signs_clients_in(): void
    {
        Http::fake([
            'plesk.example.test:8443/api/v2/clients' => Http::response(['id' => 12, 'guid' => 'c-guid']),
            'plesk.example.test:8443/api/v2/domains' => Http::response(['id' => 34, 'guid' => 'd-guid']),
            'plesk.example.test:8443/api/v2/domains/34/status' => Http::response(['status' => 'success']),
            'plesk.example.test:8443/enterprise/control/agent.php' => Http::response('<?xml version="1.0"?><packet><server><create_session><result><status>ok</status><id>abc123</id></result></create_session></server></packet>'),
        ]);

        $service = $this->service('plesk', 'plesk.example.test', ['plan' => 'Default Domain'], ['status' => ServiceStatus::Pending, 'domain' => 'razstudio.com'], ['ip_address' => '203.0.113.9', 'port' => 8443]);

        $created = app(Provisioner::class)->create($service);
        $this->assertTrue($created->success, $created->message);
        $service->refresh();
        $this->assertSameModuleData(['client_id' => 12, 'domain_id' => 34], $service->module_data);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v2/domains')
            && $request['owner_client'] === ['id' => 12]
            && $request['plan'] === ['name' => 'Default Domain']
            && $request['ipv4'] === ['203.0.113.9']
            && $request->hasHeader('X-API-Key', 'TESTTOKEN123'));

        $module = app(ExtensionManager::class)->serverModule('plesk');
        $this->assertTrue($module->suspend($service, 'Unpaid')->success);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), 'domains/34/status') && $request['status'] === 'suspended');

        $this->assertSame('https://plesk.example.test:8443/enterprise/rsession_init.php?PLESKSESSID=abc123', $module->loginUrl($service));
        Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '<login>'.$service->username.'</login>'));
    }

    public function test_plesk_removes_the_customer_when_the_subscription_fails(): void
    {
        Http::fake([
            '*/api/v2/clients' => Http::response(['id' => 12]),
            '*/api/v2/domains' => Http::response(['code' => 1007, 'message' => 'Service plan not found'], 400),
            '*/api/v2/clients/12' => Http::response(['id' => 12]),
        ]);

        $service = $this->service('plesk', 'plesk.example.test', ['plan' => 'Missing'], ['status' => ServiceStatus::Pending, 'domain' => 'razstudio.com'], ['ip_address' => '203.0.113.9', 'port' => 8443]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Service plan not found', $result->message);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v2/clients/12'));
    }

    public function test_proxmox_clones_the_template_and_waits_for_the_task(): void
    {
        Sleep::fake();
        $clone = 'UPID:pve1:0001:clone';
        $statusCalls = 0;

        Http::fake(function (Request $request) use ($clone, &$statusCalls) {
            $path = parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                str_ends_with($path, '/cluster/nextid') => Http::response(['data' => '105']),
                str_ends_with($path, '/qemu/9000/clone') => Http::response(['data' => $clone]),
                str_contains($path, '/tasks/') => Http::response(['data' => ++$statusCalls < 2 ? ['status' => 'running'] : ['status' => 'stopped', 'exitstatus' => 'OK']]),
                str_ends_with($path, '/qemu/105/config') && $request->method() === 'GET' => Http::response(['data' => ['scsi0' => 'local-lvm:vm-105-disk-0,size=10G']]),
                default => Http::response(['data' => null]),
            };
        });

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1', 'template' => '9000', 'cores' => '2', 'memory' => '2048', 'disk' => '40', 'ip_config' => 'ip=203.0.113.20/24,gw=203.0.113.1'], ['status' => ServiceStatus::Pending, 'domain' => 'vps.example.com'], ['port' => 8006, 'username' => 'root@pam!nuvabill']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSameModuleData(['vmid' => 105, 'node' => 'pve1', 'ip_config' => 'ip=203.0.113.20/24,gw=203.0.113.1', 'ip' => '203.0.113.20'], $service->fresh()->module_data);
        Sleep::assertSleptTimes(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/qemu/9000/clone') && $request['newid'] === 105 && $request['name'] === 'vps.example.com'
            && $request->hasHeader('Authorization', 'PVEAPIToken=root@pam!nuvabill=TESTTOKEN123'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/qemu/105/config') && $request['cores'] === 2 && $request['ciuser'] === 'root');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/qemu/105/resize') && $request['size'] === '40G');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/qemu/105/status/start'));
    }

    public function test_proxmox_shows_its_panel_and_runs_power_actions(): void
    {
        Http::fake([
            '*/qemu/105/status/current' => Http::response(['data' => ['status' => 'stopped', 'name' => 'vps.example.com', 'cpu' => 0, 'mem' => 0, 'maxmem' => 2147483648]]),
            '*/qemu/105/config' => Http::response(['data' => ['ipconfig0' => 'ip=203.0.113.20/24,gw=203.0.113.1']]),
            '*/qemu/105/status/start' => Http::response(['data' => 'UPID:pve1:start']),
        ]);

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['module_data' => ['vmid' => 105, 'node' => 'pve1']], ['port' => 8006]);
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee('Stopped')
            ->assertSee('203.0.113.20')
            ->assertSee('Start');

        $this->post(route('client.services.panel', [$service, 'start']))->assertSessionHas('status', 'The server is starting.');
        $this->post(route('client.services.panel', [$service, 'password']), ['password' => 'short'])->assertSessionHas('error');
    }

    public function test_plesk_terminate_of_an_imported_service_removes_only_its_own_subscription(): void
    {
        $this->fakePlesk([['id' => 33, 'name' => 'merlas.example'], ['id' => 34, 'name' => 'razsite.example', 'ascii_name' => 'razsite.example']]);

        // Imported: Nuvabill did not create the customer, so it has no stored IDs.
        $service = $this->service('plesk', 'plesk.example.test', ['plan' => 'Default Domain'], ['username' => 'razsite', 'domain' => 'razsite.example', 'module_data' => null], ['port' => 8443]);

        $result = app(Provisioner::class)->terminate($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/v2/domains/34'));
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/v2/clients'));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/domains/33'));
    }

    public function test_plesk_suspends_only_the_subscription_whose_name_matches_and_remembers_it(): void
    {
        $this->fakePlesk([['id' => 33, 'name' => 'merlas.example'], ['id' => 34, 'name' => 'RazSite.example']]);

        $service = $this->service('plesk', 'plesk.example.test', ['plan' => 'Default Domain'], ['username' => 'razsite', 'domain' => 'razsite.example', 'module_data' => null], ['port' => 8443]);

        $this->assertTrue(app(Provisioner::class)->suspend($service, 'Overdue')->success);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && str_ends_with($request->url(), '/api/v2/domains/34/status') && $request['status'] === 'suspended');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/domains/33'));
        $this->assertSameModuleData(['domain_id' => 34], $service->fresh()->module_data);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
    }

    public function test_plesk_does_nothing_when_no_single_subscription_matches_the_service(): void
    {
        $this->fakePlesk([['id' => 33, 'name' => 'merlas.example']]);

        $blank = $this->service('plesk', 'plesk.example.test', ['plan' => 'Default Domain'], ['username' => null, 'domain' => null, 'module_data' => null], ['port' => 8443]);
        $other = $this->service('plesk', 'plesk2.example.test', ['plan' => 'Default Domain'], ['username' => 'razsite', 'domain' => 'razsite.example', 'module_data' => null], ['port' => 8443]);

        foreach ([$blank, $other] as $service) {
            $this->assertFalse(app(Provisioner::class)->suspend($service, 'Overdue')->success);
            $this->assertFalse(app(Provisioner::class)->terminate($service)->success);
            $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        }

        Http::assertNotSent(fn (Request $request): bool => in_array($request->method(), ['PUT', 'DELETE'], true));
    }

    public function test_proxmox_suspend_fails_when_the_stop_task_fails(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: true, exitStatus: 'VM is locked (backup)');

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['module_data' => ['vmid' => 105, 'node' => 'pve1']], ['port' => 8006]);

        $result = app(Provisioner::class)->suspend($service, 'Overdue');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('VM is locked (backup)', $result->message);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status, 'The next nightly run tries again.');
        $this->assertSame(['PUT config onboot=0', 'GET qemu/105/status/current', 'POST qemu/105/status/stop', 'PUT config onboot=1'], $this->calls($node));
    }

    public function test_proxmox_suspend_turns_off_start_on_boot_and_unsuspend_turns_it_back_on(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: true, exitStatus: 'OK');

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['module_data' => ['vmid' => 105, 'node' => 'pve1']], ['port' => 8006]);

        $this->assertTrue(app(Provisioner::class)->suspend($service, 'Overdue')->success);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame(['PUT config onboot=0', 'GET qemu/105/status/current', 'POST qemu/105/status/stop'], $this->calls($node));

        [$node->running, $node->log] = [false, []];

        $this->assertTrue(app(Provisioner::class)->unsuspend($service->fresh())->success);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSame(['PUT config onboot=1', 'GET qemu/105/status/current', 'POST qemu/105/status/start'], $this->calls($node));
    }

    public function test_proxmox_unsuspend_that_cannot_start_the_vm_keeps_start_on_boot_off(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: false, exitStatus: 'start failed: storage is not online');

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['status' => ServiceStatus::Suspended, 'module_data' => ['vmid' => 105, 'node' => 'pve1']], ['port' => 8006]);

        $result = app(Provisioner::class)->unsuspend($service);

        $this->assertFalse($result->success);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame(['PUT config onboot=1', 'GET qemu/105/status/current', 'POST qemu/105/status/start', 'PUT config onboot=0'], $this->calls($node));
    }

    public function test_the_nightly_run_finishes_proxmox_suspensions_from_before_the_update(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: true, exitStatus: 'OK');

        $old = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['status' => ServiceStatus::Suspended, 'suspension_reason' => 'Overdue', 'module_data' => ['vmid' => 105, 'node' => 'pve1']], ['port' => 8006]);
        $active = Service::factory()->create(['product_id' => $old->product_id, 'server_id' => $old->server_id, 'module_data' => ['vmid' => 106, 'node' => 'pve1']]);
        $notMade = Service::factory()->create(['product_id' => $old->product_id, 'server_id' => $old->server_id, 'status' => ServiceStatus::Suspended, 'module_data' => null]);
        $otherModule = $this->service('virtualizor', 'vps.example.test', [], ['status' => ServiceStatus::Suspended, 'module_data' => ['vmid' => 7]]);

        (require database_path('migrations/2027_07_02_000001_servers_domains_recheck_proxmox_suspensions.php'))->up();

        $this->assertSame(7, $old->fresh()->module_data[Provisioner::RECHECK_SUSPENSION] ?? null);
        $this->assertSameModuleData(['vmid' => 106, 'node' => 'pve1'], $active->fresh()->module_data);
        $this->assertNull($notMade->fresh()->module_data);
        $this->assertSameModuleData(['vmid' => 7], $otherModule->fresh()->module_data);

        app(DailyAutomation::class)->run();

        $this->assertSame(['PUT config onboot=0', 'GET qemu/105/status/current', 'POST qemu/105/status/stop'], $this->calls($node));
        $this->assertSame(ServiceStatus::Suspended, $old->fresh()->status);
        $this->assertSameModuleData(['vmid' => 105, 'node' => 'pve1'], $old->fresh()->module_data);
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.suspension_checked', 'subject_id' => $old->id]);

        // Done once: the next night leaves it alone.
        $node->log = [];
        app(DailyAutomation::class)->run();

        $this->assertSame([], $node->log);
    }

    public function test_a_nightly_recheck_that_cannot_stop_the_vm_keeps_it_off_on_boot_and_gives_up_after_its_tries(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: true, exitStatus: 'VM is locked (backup)');

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['status' => ServiceStatus::Suspended, 'module_data' => ['vmid' => 105, 'node' => 'pve1', Provisioner::RECHECK_SUSPENSION => 2]], ['port' => 8006]);

        $this->assertSame(0, app(Provisioner::class)->recheckSuspensions());

        // Start on boot stays off: the service is still suspended.
        $this->assertSame(['PUT config onboot=0', 'GET qemu/105/status/current', 'POST qemu/105/status/stop'], $this->calls($node));
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertSame(1, $service->fresh()->module_data[Provisioner::RECHECK_SUSPENSION] ?? null);
        $this->assertStringContainsString('VM is locked (backup)', (string) ActivityLog::query()->where('action', 'service.module_failed')->latest('id')->value('description'));

        app(Provisioner::class)->recheckSuspensions();

        $this->assertSameModuleData(['vmid' => 105, 'node' => 'pve1'], $service->fresh()->module_data);
        $this->assertStringContainsString('last try', (string) ActivityLog::query()->where('action', 'service.module_failed')->latest('id')->value('description'));

        $node->log = [];
        app(Provisioner::class)->recheckSuspensions();

        $this->assertSame([], $node->log);
    }

    public function test_the_nightly_recheck_leaves_a_vps_that_was_unsuspended_while_it_waited(): void
    {
        Sleep::fake();
        $flagged = ['node' => 'pve1', Provisioner::RECHECK_SUSPENSION => 7];
        $first = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1'], ['status' => ServiceStatus::Suspended, 'module_data' => ['vmid' => 105] + $flagged, 'updated_at' => now()->subDay()], ['port' => 8006]);
        $paid = Service::factory()->create(['product_id' => $first->product_id, 'server_id' => $first->server_id, 'status' => ServiceStatus::Suspended, 'module_data' => ['vmid' => 106] + $flagged]);
        $running = [105 => true, 106 => false];
        $log = [];

        Http::fake(function (Request $request) use (&$running, &$log, $paid) {
            $path = (string) preg_replace('#^/api2/json/nodes/pve1/#', '', (string) parse_url($request->url(), PHP_URL_PATH));
            $log[] = $request->method().' '.$path.(isset($request['onboot']) ? ' onboot='.$request['onboot'] : '');

            // While the first node is slow to answer, the client of the second VPS pays and it is unsuspended.
            if ($log === ['PUT qemu/105/config onboot=0']) {
                app(Provisioner::class)->unsuspend($paid->fresh());
            }

            if (preg_match('#^qemu/(\d+)/status/(start|stop)$#', $path, $match)) {
                $running[(int) $match[1]] = $match[2] === 'start';
            }

            return match (true) {
                str_starts_with($path, 'tasks/') => Http::response(['data' => ['status' => 'stopped', 'exitstatus' => 'OK']]),
                (bool) preg_match('#^qemu/(\d+)/status/current$#', $path, $match) => Http::response(['data' => ['status' => $running[(int) $match[1]] ? 'running' : 'stopped']]),
                str_contains($path, '/status/') => Http::response(['data' => 'UPID:pve1:0002:power']),
                default => Http::response(['data' => null]),
            };
        });

        $this->assertSame(1, app(Provisioner::class)->recheckSuspensions());

        $this->assertSame(ServiceStatus::Active, $paid->fresh()->status);
        $this->assertTrue($running[106], 'The VPS that was paid for keeps running.');
        $this->assertFalse($running[105]);
        $this->assertNotContains('PUT qemu/106/config onboot=0', $log);
        $this->assertNotContains('POST qemu/106/status/stop', $log);
        $this->assertSameModuleData(['vmid' => 106, 'node' => 'pve1'], $paid->fresh()->module_data, 'An unsuspended VPS is not checked again.');
        $this->assertSame(ServiceStatus::Suspended, $first->fresh()->status);
    }

    public function test_a_stopped_setup_job_is_logged_for_staff(): void
    {
        $service = Service::factory()->pending()->create();

        (new ProvisionService($service))->failed(new RuntimeException('The job ran too long.'));

        $this->assertDatabaseHas('activity_logs', ['action' => 'service.module_failed', 'subject_id' => $service->id]);
        $this->assertStringContainsString('The job ran too long.', (string) ActivityLog::query()->where('action', 'service.module_failed')->value('description'));
    }

    public function test_proxmox_treats_a_task_that_ended_with_warnings_as_done(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: false, exitStatus: 'WARNINGS: 1');

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1', 'template' => '9000', 'cores' => '2', 'memory' => '2048'], ['status' => ServiceStatus::Pending], ['port' => 8006]);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
        $this->assertSameModuleData(['vmid' => 105, 'node' => 'pve1', 'ip_config' => 'ip=dhcp'], $service->fresh()->module_data);

        // A task that really failed still fails.
        $node->exitStatus = 'unable to remove disk';

        $this->assertFalse(app(Provisioner::class)->terminate($service->fresh())->success);
        $this->assertSame(ServiceStatus::Active, $service->fresh()->status);
    }

    public function test_proxmox_deletes_the_clone_when_a_later_step_fails(): void
    {
        Sleep::fake();
        $node = $this->fakeProxmox(running: false, exitStatus: 'OK', configFails: true);

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1', 'template' => '9000', 'cores' => '2', 'memory' => '2048', 'ip_config' => 'ip=bad'], ['status' => ServiceStatus::Pending], ['port' => 8006]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('ipconfig0', $result->message);
        $this->assertStringNotContainsString('VM 105', $result->message, 'The VM was removed, so staff need not check it.');
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertNull($service->fresh()->module_data);
        $this->assertSame(['POST qemu/9000/clone', 'PUT config', 'GET qemu/105/status/current', 'DELETE qemu/105'], $this->calls($node));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/nodes/pve1/qemu/105?') && str_contains($request->url(), 'purge=1'));
    }

    public function test_proxmox_stops_a_clone_that_takes_longer_than_the_setup_may_wait(): void
    {
        Sleep::fake(syncWithCarbon: true);
        $node = $this->fakeProxmox(running: false, exitStatus: null);

        $service = $this->service('proxmox', 'pve.example.test', ['node' => 'pve1', 'template' => '9000', 'cores' => '2', 'memory' => '2048'], ['status' => ServiceStatus::Pending], ['port' => 8006]);
        $started = now();

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Proxmox did not finish the task in time.', $result->message);
        $this->assertStringContainsString('VM 105', $result->message);
        $this->assertLessThan((new ProvisionService($service))->timeout - 60, $started->diffInSeconds(now()), 'The setup job is stopped at its time limit, so create() ends well before it.');
        $this->assertGreaterThanOrEqual(600, $started->diffInSeconds(now()), 'A slow full clone gets several minutes.');
        $this->assertSame('DELETE tasks', last($this->calls($node)));
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/qemu/105/config'));
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
    }

    public function test_proxmox_refuses_a_fixed_ip_that_another_service_already_uses(): void
    {
        Http::fake();
        $config = ['node' => 'pve1', 'template' => '9000', 'cores' => '1', 'memory' => '1024', 'ip_config' => 'ip=203.0.113.20/24,gw=203.0.113.1'];

        // Made before a VPS remembered its network, so its product's setting counts.
        $first = $this->service('proxmox', 'pve.example.test', $config, ['module_data' => ['vmid' => 104, 'node' => 'pve1']], ['port' => 8006]);
        $second = Service::factory()->pending()->create(['product_id' => $first->product_id, 'server_id' => $first->server_id]);

        $result = app(Provisioner::class)->create($second);

        $this->assertFalse($result->success);
        $this->assertSame('This product has a fixed IP that another service already uses. Give each VPS its own IP.', $result->message);
        $this->assertSame(ServiceStatus::Pending, $second->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_proxmox_checks_the_ip_each_vps_got_not_its_products_current_setting(): void
    {
        Sleep::fake();
        $this->fakeProxmox(running: false, exitStatus: 'OK');
        $config = ['node' => 'pve1', 'template' => '9000', 'cores' => '1', 'memory' => '1024', 'ip_config' => 'ip=203.0.113.10/24,gw=203.0.113.1'];
        $first = $this->service('proxmox', 'pve.example.test', $config, ['status' => ServiceStatus::Pending], ['port' => 8006]);

        $this->assertTrue(app(Provisioner::class)->create($first)->success);
        $this->assertSame('203.0.113.10', $first->fresh()->module_data['ip'] ?? null);

        // Staff give the product the next free IP for its next sale: the first VPS keeps its own.
        $first->product->update(['module_config' => ['ip_config' => 'ip=203.0.113.11/24,gw=203.0.113.1'] + $config]);
        $second = Service::factory()->pending()->create(['product_id' => $first->product_id, 'server_id' => $first->server_id]);

        $result = app(Provisioner::class)->create($second);

        $this->assertTrue($result->success, $result->message);
        $this->assertSame('203.0.113.11', $second->fresh()->module_data['ip'] ?? null);

        // Another product set to the first VPS's IP would knock it offline.
        $other = Product::factory()->create(['server_module' => 'proxmox', 'server_id' => $first->server_id, 'module_config' => $config]);
        $third = Service::factory()->pending()->create(['product_id' => $other->id, 'server_id' => $first->server_id]);

        $result = app(Provisioner::class)->create($third);

        $this->assertFalse($result->success);
        $this->assertSame('This product has a fixed IP that another service already uses. Give each VPS its own IP.', $result->message);
        $this->assertSame(ServiceStatus::Pending, $third->fresh()->status);

        // Once the first VPS is gone, its IP is free again.
        $first->update(['status' => ServiceStatus::Terminated]);

        $this->assertTrue(app(Provisioner::class)->create($third->fresh())->success);
    }

    public function test_cpanel_sends_the_account_password_in_the_body_not_the_url(): void
    {
        Http::fake(['*/json-api/createacct' => Http::response(['metadata' => ['result' => 1, 'reason' => 'Account Creation Ok']])]);

        $service = $this->service('cpanel', 'whm.example.test', ['package' => 'starter'], ['status' => ServiceStatus::Pending, 'domain' => 'razstudio.com'], ['port' => 2087]);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);
        $password = (string) $service->fresh()->password;

        $this->assertNotSame('', $password);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://whm.example.test:2087/json-api/createacct'
            && ! str_contains($request->url(), 'password')
            && ! str_contains($request->url(), rawurlencode($password))
            && $request['password'] === $password
            && $request['api.version'] === 1
            && $request['domain'] === 'razstudio.com');
    }

    /**
     * Plesk with stored-ID-free lookups: the domains list ignores the name filter and lists $domains.
     *
     * @param  list<array<string, mixed>>  $domains
     */
    private function fakePlesk(array $domains): void
    {
        Http::fake(function (Request $request) use ($domains) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match (true) {
                $request->method() === 'GET' && str_ends_with($path, '/api/v2/clients') => Http::response([['id' => 5, 'login' => 'merlas']]),
                $request->method() === 'GET' && str_ends_with($path, '/api/v2/domains') => Http::response($domains),
                default => Http::response(['status' => 'success']),
            };
        });
    }

    /**
     * A Proxmox node with VM 105, cloned from template 9000. Change the returned node's fields to
     * change its answers: tasks end with exitStatus, or keep running when it is null. Its log
     * lists each request as "METHOD what".
     */
    private function fakeProxmox(bool $running, ?string $exitStatus, bool $configFails = false): \stdClass
    {
        $node = (object) ['running' => $running, 'exitStatus' => $exitStatus, 'configFails' => $configFails, 'log' => []];

        Http::fake(function (Request $request) use ($node) {
            $path = (string) preg_replace('#^/api2/json/(nodes/pve1/)?#', '', (string) parse_url($request->url(), PHP_URL_PATH));
            $method = $request->method();
            $onbootOnly = $method === 'PUT' && str_ends_with($path, '/config') && array_keys($request->data()) === ['onboot'];

            $node->log[] = match (true) {
                str_starts_with($path, 'tasks/') => $method === 'DELETE' ? 'DELETE tasks' : 'GET task',
                $onbootOnly => 'PUT config onboot='.$request['onboot'],
                str_ends_with($path, '/config') => $method.' config',
                default => $method.' '.$path,
            };

            return match (true) {
                $path === 'cluster/nextid' => Http::response(['data' => '105']),
                $path === 'qemu/9000/clone' => Http::response(['data' => 'UPID:pve1:0001:clone']),
                str_starts_with($path, 'tasks/') && $method === 'DELETE' => Http::response(['data' => null]),
                str_starts_with($path, 'tasks/') => Http::response(['data' => $node->exitStatus === null ? ['status' => 'running'] : ['status' => 'stopped', 'exitstatus' => $node->exitStatus]]),
                $path === 'qemu/105/status/current' => Http::response(['data' => ['status' => $node->running ? 'running' : 'stopped']]),
                in_array($path, ['qemu/105/status/stop', 'qemu/105/status/start'], true) => Http::response(['data' => 'UPID:pve1:0002:power']),
                $node->configFails && $method === 'PUT' && $path === 'qemu/105/config' && ! $onbootOnly => Http::response(['data' => null, 'errors' => ['ipconfig0' => 'invalid format']], 400),
                $path === 'qemu/105' && $method === 'DELETE' => Http::response(['data' => 'UPID:pve1:0003:destroy']),
                default => Http::response(['data' => null]),
            };
        });

        return $node;
    }

    /**
     * The node's requests, without the task status checks in between.
     *
     * @return list<string>
     */
    private function calls(\stdClass $node): array
    {
        return array_values(array_filter($node->log, fn (string $line): bool => $line !== 'GET task' && $line !== 'GET cluster/nextid'));
    }

    /**
     * @param  array<string, string>  $config
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $serverAttributes
     */
    private function service(string $module, string $hostname, array $config, array $attributes = [], array $serverAttributes = []): Service
    {
        $server = Server::factory()->create($serverAttributes + ['module' => $module, 'hostname' => $hostname, 'port' => 4085]);
        $product = Product::factory()->create(['server_module' => $module, 'server_id' => $server->id, 'module_config' => $config]);

        return Service::factory()->create($attributes + ['product_id' => $product->id, 'server_id' => $server->id]);
    }

    /**
     * MySQL 8 keeps JSON objects with their keys in its own order, so only the keys and values are compared.
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertSameModuleData(array $expected, mixed $actual, string $message = ''): void
    {
        $this->assertIsArray($actual, $message);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual, $message);
    }
}
