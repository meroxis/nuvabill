<?php

namespace Tests\Feature\Admin;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Role;
use App\Models\Server;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

    public function test_an_extension_added_by_hand_is_marked_and_can_be_moved_to_quarantine_when_unused(): void
    {
        $root = storage_path('framework/testing/extensions-'.uniqid());
        File::copyDirectory(base_path('extensions/gateways/wayl'), $root.'/gateways/wayl');
        File::ensureDirectoryExists($root.'/gateways/wayl-checkout');
        File::put($root.'/gateways/wayl-checkout/extension.json', json_encode([
            'slug' => 'wayl-checkout', 'type' => 'gateway', 'name' => 'Wayl', 'version' => '1.0.0',
            'namespace' => 'Acme\\WaylCheckout\\', 'class' => 'Acme\\WaylCheckout\\Gateway',
            'description' => 'Wayl checkout with a configurable USD-to-IQD rate',
        ]));
        config(['nuvabill.extensions_path' => $root]);
        $this->app->forgetInstance(ExtensionManager::class);
        $this->signInAdmin();

        $this->get(route('admin.extensions.index'))->assertOk()
            ->assertSee('Added by hand')
            ->assertSee('extensions/gateways/wayl-checkout');

        $this->delete(route('admin.extensions.destroy', 'wayl'))->assertSessionHas('error');
        $this->assertDirectoryExists($root.'/gateways/wayl', 'Built-in extensions stay');

        app(ExtensionManager::class)->saveSettings('wayl-checkout', [], true);
        $this->delete(route('admin.extensions.destroy', 'wayl-checkout'))->assertSessionHas('error');
        $this->assertDirectoryExists($root.'/gateways/wayl-checkout', 'A switched-on extension stays');

        app(ExtensionManager::class)->saveSettings('wayl-checkout', [], false);
        $this->delete(route('admin.extensions.destroy', 'wayl-checkout'))->assertSessionHas('status');
        $this->assertDirectoryDoesNotExist($root.'/gateways/wayl-checkout');
        $this->assertNotEmpty(glob(storage_path('app/quarantine/*/extensions/gateways/wayl-checkout/extension.json')));
        $this->assertNull(app(ExtensionManager::class)->find('wayl-checkout'));

        File::deleteDirectory($root);
        File::deleteDirectory(storage_path('app/quarantine'));
    }

    public function test_the_old_lists_open_the_extensions_page(): void
    {
        $this->signInAdmin();

        $this->get(route('admin.settings.gateways.index'))->assertRedirect(route('admin.extensions.index', ['tab' => 'gateways']));
        $this->get(route('admin.servers.create', ['module' => 'directadmin']))->assertOk()->assertSee('"directadmin"', false);
    }
}
