<?php

namespace Tests\Feature\Servers;

use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelLoginFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_panel_that_signs_in_with_a_form_gets_a_page_that_sends_it(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);

        $server = Server::factory()->create(['module' => 'formpanel', 'hostname' => 'panel.example.test']);
        $product = Product::factory()->create(['server_module' => 'formpanel', 'server_id' => $server->id]);
        $service = Service::factory()->create(['product_id' => $product->id, 'server_id' => $server->id, 'status' => ServiceStatus::Active, 'username' => 'razweb', 'password' => 'Secret12345']);

        $this->actingAs(Client::factory()->create(), 'web');
        $this->post(route('client.services.login', $service))->assertNotFound();

        $this->actingAs($service->client, 'web');
        $this->post(route('client.services.login', $service))
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('action="https://panel.example.test:8090/login"', false)
            ->assertSee('name="username" value="razweb"', false);

        $service->update(['status' => ServiceStatus::Suspended]);
        $this->post(route('client.services.login', $service))->assertRedirect()->assertSessionHas('error');
    }
}
