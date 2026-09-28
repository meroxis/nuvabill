<?php

namespace Tests\Feature\Admin;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Role;
use App\Models\Server;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtensionsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_see_every_extension_with_its_status_and_use(): void
    {
        $this->signInAdmin();
        $this->enableGateway('banktransfer', ['instructions' => 'Pay to IBAN 123']);
        $this->enableGateway('stripe');
        Transaction::query()->create(['client_id' => Client::factory()->create()->id, 'gateway' => 'banktransfer', 'reference' => 'bank-1', 'type' => 'payment', 'amount' => 1000, 'currency' => 'USD', 'paid_at' => now()->subDays(3)]);
        Server::factory()->create(['module' => 'cpanel']);

        $this->get(route('admin.extensions.index'))->assertOk()
            ->assertSee('Bank transfer')
            ->assertSee('Stripe')
            ->assertSee('cPanel')
            ->assertSee('Needs settings')
            ->assertSee('Payments in the last 30 days: 1')
            ->assertSee('Servers: 1 · Accounts: 0');

        $this->get(route('admin.extensions.index', ['tab' => 'gateways']))->assertOk()->assertSee('Stripe')->assertDontSee('Servers: 1');
        $this->get(route('admin.extensions.index', ['q' => 'stripe']))->assertOk()->assertSee('Stripe')->assertDontSee('Bank transfer');

        $this->get(route('admin.dashboard'))->assertSee('<span class="count" data-tone="warn"', false);
    }

    public function test_switching_on_asks_for_missing_settings_first(): void
    {
        $this->signInAdmin();
        $extensions = app(ExtensionManager::class);

        $this->post(route('admin.extensions.toggle', 'stripe'))
            ->assertRedirect(route('admin.settings.gateways.edit', 'stripe'))
            ->assertSessionHas('error');
        $this->assertFalse($extensions->isEnabled('stripe'));

        $extensions->saveSettings('banktransfer', ['instructions' => 'Pay to IBAN 123'], false);
        $this->post(route('admin.extensions.toggle', 'banktransfer'))->assertSessionHas('status');
        $this->assertTrue(app(ExtensionManager::class)->isEnabled('banktransfer'));
        $this->assertSame('Pay to IBAN 123', app(ExtensionManager::class)->settings('banktransfer')['instructions'], 'Settings are kept');

        $this->post(route('admin.extensions.toggle', 'banktransfer'))->assertSessionHas('status');
        $this->assertFalse(app(ExtensionManager::class)->isEnabled('banktransfer'));
    }

    public function test_each_type_needs_its_own_permission(): void
    {
        $role = Role::query()->create(['name' => 'Products only', 'permissions' => ['products.manage']]);
        $this->signInAdmin(Admin::factory()->create(['role_id' => $role->id]));

        $this->get(route('admin.extensions.index'))->assertOk()->assertSee('Add a server')->assertDontSee(route('admin.settings.gateways.edit', 'stripe'));
        $this->post(route('admin.extensions.toggle', 'banktransfer'))->assertForbidden();

        $this->signInAdmin(Admin::factory()->create(['role_id' => Role::query()->where('name', 'Support')->value('id')]));
        $this->get(route('admin.extensions.index'))->assertForbidden();
    }

    public function test_the_old_lists_open_the_extensions_page(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.settings.gateways.index'))->assertRedirect(route('admin.extensions.index', ['tab' => 'gateways']));
        $this->get(route('admin.servers.create', ['module' => 'directadmin']))->assertOk()->assertSee('"directadmin"', false);
    }
}
