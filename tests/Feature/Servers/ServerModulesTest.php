<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Provisioning\Provisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ServerModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_virtualizor_creates_a_vps_and_remembers_its_id(): void
    {
        Http::fake(['vz.example.test:4085/*' => Http::response(['done' => 1, 'vs_info' => ['vpsid' => 77, 'ips' => ['203.0.113.7']]])]);

        $service = $this->service('virtualizor', 'vz.example.test', ['virt' => 'kvm', 'os_id' => '100', 'plan_id' => '3'], ['status' => ServiceStatus::Pending, 'domain' => 'vps1.example.com']);

        $result = app(Provisioner::class)->create($service);

        $this->assertTrue($result->success, $result->message);
        $service->refresh();
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame('77', $service->module_data['vpsid']);
        $this->assertSame('root', $service->username);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'act=addvs')
            && str_contains($request->url(), 'adminapikey=TESTTOKEN123')
            && $request['virt'] === 'kvm'
            && $request['osid'] === 100
            && $request['plid'] === 3
            && $request['hostname'] === 'vps1.example.com');
    }

    public function test_clients_manage_their_virtualizor_vps_inside_the_client_area(): void
    {
        Http::fake([
            '*act=ostemplates*' => Http::response(['ostemplates' => ['100' => ['osid' => 100, 'type' => 'kvm', 'name' => 'Ubuntu 24.04'], '200' => ['osid' => 200, 'type' => 'openvz', 'name' => 'CentOS 7']]]),
            '*vs_status*' => Http::response(['status' => ['77' => ['status' => 1, 'used_cpu' => 12.5, 'used_ram' => 512, 'ram' => 2048, 'used_disk' => 8, 'disk' => 40, 'used_bandwidth' => 3, 'bandwidth' => 1000]]]),
            '*act=vs&action=restart*' => Http::response(['done' => 1]),
            '*act=vs&vpsid=77*' => Http::response(['vs' => ['77' => ['hostname' => 'vps1.example.com', 'os_name' => 'Ubuntu 24.04', 'virt' => 'kvm', 'ips' => ['203.0.113.7']]]]),
            '*act=rebuild*' => Http::response(['done' => 1]),
        ]);

        $service = $this->service('virtualizor', 'vz.example.test', ['virt' => 'kvm', 'os_id' => '100'], ['module_data' => ['vpsid' => '77'], 'username' => 'root']);
        $this->actingAs($service->client, 'web');

        $this->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSee('Manage your server')
            ->assertSee('Running')
            ->assertSee('203.0.113.7')
            ->assertSee('Ubuntu 24.04')
            ->assertDontSee('CentOS 7')
            ->assertDontSee('Open control panel');

        $this->from(route('client.services.show', $service))
            ->post(route('client.services.panel', [$service, 'restart']))
            ->assertRedirect(route('client.services.show', $service))
            ->assertSessionHas('status', 'The VPS is restarting.');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'action=restart') && str_contains($request->url(), 'vpsid=77'));

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

        $service = $this->service('plesk', 'plesk.example.test', ['plan' => 'Default Domain'], ['status' => ServiceStatus::Pending, 'domain' => 'danasbakery.com'], ['ip_address' => '203.0.113.9', 'port' => 8443]);

        $created = app(Provisioner::class)->create($service);
        $this->assertTrue($created->success, $created->message);
        $service->refresh();
        $this->assertSame(['client_id' => 12, 'domain_id' => 34], $service->module_data);
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

        $service = $this->service('plesk', 'plesk.example.test', ['plan' => 'Missing'], ['status' => ServiceStatus::Pending, 'domain' => 'danasbakery.com'], ['ip_address' => '203.0.113.9', 'port' => 8443]);

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
        $this->assertSame(['vmid' => 105, 'node' => 'pve1'], $service->fresh()->module_data);
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
}
