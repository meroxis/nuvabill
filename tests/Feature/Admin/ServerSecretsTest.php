<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Role;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Staff who manage servers cannot see the saved root token or password, so they must not be able
 * to send them to another host either.
 */
class ServerSecretsTest extends TestCase
{
    use RefreshDatabase;

    private Server $server;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::query()->create(['name' => 'Products only', 'permissions' => ['products.manage']]);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz', 'role_id' => $role->id]));

        $this->server = Server::factory()->create([
            'name' => 'WHM 1',
            'module' => 'cpanel',
            'hostname' => 'whm.example.com',
            'port' => 2087,
            'use_ssl' => true,
            'username' => 'root',
            'api_token' => 'stored-root-token',
        ]);
    }

    public function test_changing_the_host_without_a_new_token_is_refused_and_nothing_is_sent_there(): void
    {
        $this->put(route('admin.servers.update', $this->server), $this->form(['hostname' => 'collector.example.net', 'use_ssl' => 0, 'port' => 80]))
            ->assertSessionHasErrors('api_token');

        $server = $this->server->fresh();
        $this->assertSame('whm.example.com', $server->hostname);
        $this->assertSame('stored-root-token', $server->api_token);

        Http::fake(['*' => Http::response(['metadata' => ['result' => 1], 'data' => ['version' => '11']])]);
        $this->post(route('admin.servers.test', $this->server));
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'collector.example.net'));
    }

    public function test_changing_the_module_or_ssl_also_needs_the_secret_again(): void
    {
        $this->put(route('admin.servers.update', $this->server), $this->form(['use_ssl' => 0]))->assertSessionHasErrors('api_token');
        $this->put(route('admin.servers.update', $this->server), $this->form(['module' => 'directadmin', 'port' => 2222]))->assertSessionHasErrors('api_token');

        $this->assertSame('stored-root-token', $this->server->fresh()->api_token);
        $this->assertTrue($this->server->fresh()->use_ssl);
    }

    public function test_editing_only_the_name_keeps_the_saved_token(): void
    {
        $this->put(route('admin.servers.update', $this->server), $this->form(['name' => 'WHM main']))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.servers.index'));

        $server = $this->server->fresh();
        $this->assertSame('WHM main', $server->name);
        $this->assertSame('stored-root-token', $server->api_token);
    }

    public function test_changing_the_host_with_a_new_token_replaces_both_secrets(): void
    {
        $this->server->update(['password' => 'stored-root-password']);

        $this->put(route('admin.servers.update', $this->server), $this->form(['hostname' => 'whm2.example.com', 'api_token' => 'new-token']))
            ->assertSessionHasNoErrors();

        $server = $this->server->fresh();
        $this->assertSame('whm2.example.com', $server->hostname);
        $this->assertSame('new-token', $server->api_token);
        $this->assertNull($server->password);
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'name' => 'WHM 1',
            'module' => 'cpanel',
            'hostname' => 'whm.example.com',
            'ip_address' => '',
            'port' => '2087',
            'use_ssl' => '1',
            'username' => 'root',
            'password' => '',
            'api_token' => '',
            'max_accounts' => '',
            'nameservers' => 'ns1.example.test ns2.example.test',
            'is_active' => '1',
        ];
    }
}
