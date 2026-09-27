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
use Tests\TestCase;

class DirectAdminModuleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function directAdminService(Server $server, array $attributes = []): Service
    {
        return Service::factory()->create([
            'client_id' => Client::factory()->create(['email' => 'raz@example.test'])->id,
            'product_id' => Product::factory()->directadmin($server)->create()->id,
            'server_id' => $server->id,
            'domain' => 'razstudio.com',
        ] + $attributes);
    }

    public function test_creating_a_service_creates_the_directadmin_account(): void
    {
        Http::fake(['*/CMD_API_ACCOUNT_USER' => Http::response('error=0&text=User created&details=')]);

        $server = Server::factory()->directadmin()->create(['hostname' => 'da.example.test']);
        $service = $this->directAdminService($server, ['status' => ServiceStatus::Pending, 'server_id' => null]);

        $result = app(Provisioner::class)->create($service);

        $service->refresh();
        $this->assertTrue($result->success);
        $this->assertSame(ServiceStatus::Active, $service->status);
        $this->assertSame($server->id, $service->server_id);
        $this->assertMatchesRegularExpression('/^razstud[a-z0-9]{3}$/', $service->username);
        $this->assertNotNull($service->password);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://da.example.test:2222/CMD_API_ACCOUNT_USER'
            && $request['action'] === 'create'
            && $request['username'] === $service->username
            && $request['passwd'] === $service->password
            && $request['domain'] === 'razstudio.com'
            && $request['package'] === 'starter'
            && $request['email'] === 'raz@example.test'
            && $request['ip'] === '203.0.113.10'
            && $request->header('Authorization')[0] === 'Basic '.base64_encode('admin:TESTTOKEN123'));
    }

    public function test_a_directadmin_error_leaves_the_service_pending_with_the_reason(): void
    {
        Http::fake(['*/CMD_API_ACCOUNT_USER' => Http::response('error=1&text=Cannot Create Account&details=The package starter does not exist<br>')]);

        $service = $this->directAdminService(Server::factory()->directadmin()->create(), ['status' => ServiceStatus::Pending]);

        $result = app(Provisioner::class)->create($service);

        $this->assertFalse($result->success);
        $this->assertSame('Cannot Create Account: The package starter does not exist', $result->message);
        $this->assertSame(ServiceStatus::Pending, $service->fresh()->status);
        $this->assertDatabaseHas('activity_logs', ['action' => 'service.module_failed', 'subject_id' => $service->id]);
    }

    public function test_the_account_ip_comes_from_directadmin_when_the_server_has_none(): void
    {
        Http::fake([
            '*/CMD_API_SHOW_RESELLER_IPS*' => Http::response('list[]=198.51.100.7&list[]=198.51.100.8'),
            '*/CMD_API_ACCOUNT_USER' => Http::response('error=0&text=User created'),
        ]);

        $service = $this->directAdminService(Server::factory()->directadmin()->create(['ip_address' => null]), ['status' => ServiceStatus::Pending]);

        $this->assertTrue(app(Provisioner::class)->create($service)->success);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/CMD_API_ACCOUNT_USER') && $request['ip'] === '198.51.100.7');
    }

    public function test_suspend_unsuspend_change_package_and_terminate_send_the_right_commands(): void
    {
        Http::fake(['*' => Http::response('error=0&text=Success')]);

        $service = $this->directAdminService(Server::factory()->directadmin()->create(), ['username' => 'razacc']);
        $provisioner = app(Provisioner::class);

        $this->assertTrue($provisioner->suspend($service, 'Overdue on payment')->success);
        $this->assertSame(ServiceStatus::Suspended, $service->fresh()->status);
        $this->assertTrue($provisioner->unsuspend($service->fresh())->success);
        $this->assertTrue($provisioner->changePackage($service->fresh())->success);
        $this->assertTrue($provisioner->terminate($service->fresh())->success);
        $this->assertSame(ServiceStatus::Terminated, $service->fresh()->status);

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/CMD_API_SELECT_USERS') && ($request->data()['suspend'] ?? null) === 'Suspend' && $request['select0'] === 'razacc');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/CMD_API_SELECT_USERS') && ($request->data()['suspend'] ?? null) === 'Unsuspend' && $request['select0'] === 'razacc');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/CMD_API_MODIFY_USER') && $request['action'] === 'package' && $request['user'] === 'razacc' && $request['package'] === 'starter');
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/CMD_API_SELECT_USERS') && ($request->data()['delete'] ?? null) === 'yes' && $request['confirmed'] === 'Confirm' && $request['select0'] === 'razacc');
    }

    public function test_the_connection_test_reports_a_rejected_login(): void
    {
        Http::fake([
            'ok.example.test:2222/*' => Http::response('error=0&text=Login OK'),
            'bad.example.test:2222/*' => Http::response('<html><form action="/CMD_LOGIN"></form></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $provisioner = app(Provisioner::class);

        $this->assertTrue($provisioner->testConnection(Server::factory()->directadmin()->create(['hostname' => 'ok.example.test']))->success);

        $failed = $provisioner->testConnection(Server::factory()->directadmin()->create(['hostname' => 'bad.example.test']));
        $this->assertFalse($failed->success);
        $this->assertSame('DirectAdmin rejected the username or login key.', $failed->message);
    }

    public function test_clients_get_a_one_time_login_link_as_their_own_user(): void
    {
        Http::fake(['*/api/login/url' => Http::response(['url' => 'https://da.example.test:2222/api/login/url?key=abc'])]);

        $service = $this->directAdminService(Server::factory()->directadmin()->create(), ['username' => 'razacc']);

        $this->assertSame('https://da.example.test:2222/api/login/url?key=abc', app(Provisioner::class)->loginUrl($service));

        Http::assertSent(fn (Request $request): bool => $request->header('Authorization')[0] === 'Basic '.base64_encode('admin|razacc:TESTTOKEN123'));
    }

    public function test_directadmin_usernames_follow_directadmin_rules(): void
    {
        $module = app(ExtensionManager::class)->serverModule('directadmin');

        foreach (['testsite.com', '123abc.net', 'my-very-long-domain-name.org', 'x.io', 'admin.com'] as $domain) {
            $username = $module->makeUsername($domain);

            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9]{1,9}$/', $username);
            $this->assertStringStartsNotWith('admin', $username);
        }
    }
}
