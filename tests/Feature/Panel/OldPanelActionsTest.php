<?php

namespace Tests\Feature\Panel;

use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Models\Client;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\OldPanel\OldPanelModule;
use Tests\TestCase;

/**
 * The older client panel (HasClientPanel, without panel sections): what an action gives the client
 * to see once is only ever in the answer to the page's script, never in the session or the page.
 */
class OldPanelActionsTest extends TestCase
{
    use RefreshDatabase;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();

        $server = Server::factory()->create(['module' => 'oldpanel', 'hostname' => 'node.example.test']);
        $product = Product::factory()->create(['server_module' => 'oldpanel', 'server_id' => $server->id]);
        $this->service = Service::factory()->create([
            'client_id' => Client::factory()->create(['first_name' => 'Mer'])->id,
            'product_id' => $product->id,
            'server_id' => $server->id,
            'status' => ServiceStatus::Active,
        ]);

        $this->actingAs($this->service->client, 'web');
    }

    public function test_vnc_details_come_once_in_an_answer_that_is_not_cached(): void
    {
        $answer = $this->postJson(route('client.services.panel', [$this->service, 'vnc']))
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'message' => 'VNC details are shown below.',
                'pending' => null,
                'state' => 'unknown',
                'secret' => [
                    ['label' => 'IP address', 'value' => '203.0.113.9'],
                    ['label' => 'Port', 'value' => '5901'],
                    ['label' => 'Password', 'value' => OldPanelModule::SECRET],
                ],
            ]);

        $this->assertStringContainsString('no-store', (string) $answer->headers->get('Cache-Control'));
        $this->assertNothingKept();

        // A module that answers with ModuleResult::secret() gets the same; empty rows are left out.
        $this->postJson(route('client.services.panel', [$this->service, 'keys']))
            ->assertOk()
            ->assertJsonPath('secret', [['label' => 'Access key', 'value' => OldPanelModule::SECRET]]);
        $this->assertNothingKept();
    }

    public function test_without_javascript_the_details_are_not_shown_and_not_kept(): void
    {
        foreach (['vnc', 'keys'] as $action) {
            $this->post(route('client.services.panel', [$this->service, $action]))
                ->assertRedirect()
                ->assertSessionHas('error', 'Turn on JavaScript to do this.')
                ->assertSessionMissing('status')
                ->assertSessionMissing('panel_result');

            $this->assertNothingKept();
        }
    }

    public function test_only_the_running_power_action_is_kept_for_the_next_page(): void
    {
        $this->post(route('client.services.panel', [$this->service, 'restart']))
            ->assertSessionHas('status', 'The server is restarting.')
            ->assertSessionHas('panel_result', ['pending' => 'restart']);
        $this->assertNothingKept();

        $this->postJson(route('client.services.panel', [$this->service, 'restart']))
            ->assertOk()
            ->assertExactJson(['ok' => true, 'message' => 'The server is restarting.', 'pending' => 'restart', 'state' => 'unknown']);
    }

    public function test_the_panel_asks_for_the_details_with_its_script(): void
    {
        $html = $this->get(route('client.services.show', $this->service))->assertOk()->getContent();

        $this->assertStringContainsString('action="'.route('client.services.panel', [$this->service, 'vnc']).'" x-on:submit.prevent="reveal($event)"', $html);
        $this->assertStringContainsString('Show VNC details', $html);
        $this->assertStringContainsString('Turn on JavaScript to do this.', $html);
        $this->assertStringContainsString('Save this now. It is shown only once.', $html);
        $this->assertStringNotContainsString(OldPanelModule::SECRET, $html);
    }

    /**
     * Neither the session nor the service page that follows holds the value.
     */
    private function assertNothingKept(): void
    {
        $this->assertStringNotContainsString(OldPanelModule::SECRET, (string) json_encode(session()->all()));
        $this->assertStringNotContainsString(OldPanelModule::SECRET, (string) $this->get(route('client.services.show', $this->service))->assertOk()->getContent());
    }
}
