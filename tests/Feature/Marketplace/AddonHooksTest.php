<?php

namespace Tests\Feature\Marketplace;

use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Extension;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Fixtures\Addons\ProbeAddon;
use Tests\TestCase;

/**
 * The add-on hooks from Nuvabill 0.4.2: scheduled work, admin pages, a settings panel,
 * values the add-on keeps for itself, and translations shipped with the add-on.
 */
class AddonHooksTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        $this->app->forgetInstance(ExtensionManager::class);
        $extensions = app(ExtensionManager::class);
        $extensions->registerViews();
        $extensions->saveSettings('probe', ['label' => 'First'], true);
        ProbeAddon::$runs = 0;

        // The app registered its routes before this test pointed at the fixture add-ons.
        Route::middleware(['web', 'auth:admin', 'admin.can:marketplace.manage'])
            ->prefix(config('nuvabill.admin_path').'/addons')->name('admin.addons.')
            ->group(fn () => $extensions->registerAddonRoutes());
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_switched_on_addons_add_scheduled_work(): void
    {
        $schedule = new Schedule;
        app(ExtensionManager::class)->scheduleAddons($schedule);

        $event = collect($schedule->events())->firstWhere('description', 'probe-run');
        $this->assertNotNull($event);
        $event->run($this->app);
        $this->assertSame(1, ProbeAddon::$runs);

        app(ExtensionManager::class)->saveSettings('probe', [], false);
        $schedule = new Schedule;
        app(ExtensionManager::class)->scheduleAddons($schedule);
        $this->assertNull(collect($schedule->events())->firstWhere('description', 'probe-run'));
    }

    public function test_addon_pages_keep_their_values_when_the_settings_form_is_saved(): void
    {
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->get(route('admin.marketplace.settings', 'probe'))->assertOk()->assertSee('Probe is not connected.');

        $this->post(route('admin.addons.probe.connect'))->assertRedirect(route('admin.marketplace.settings', 'probe'));
        $this->assertSame('secret-token', app(ExtensionManager::class)->settings('probe')['token']);
        $this->get(route('admin.marketplace.settings', 'probe'))->assertSee('Probe is connected.');

        $this->put(route('admin.marketplace.settings.update', 'probe'), ['enabled' => '1', 'settings' => ['label' => 'Second']])->assertRedirect();
        $settings = app(ExtensionManager::class)->settings('probe');
        $this->assertSame('Second', $settings['label']);
        $this->assertSame('secret-token', $settings['token']);
    }

    public function test_values_an_addon_remembers_do_not_undo_newer_settings(): void
    {
        $extensions = app(ExtensionManager::class);
        $addon = $extensions->addon('probe');
        // Read once, like a long job that started before staff changed anything.
        $this->assertSame('First', $extensions->settings('probe')['label']);

        // Staff save the settings form in another request meanwhile.
        Extension::query()->where('slug', 'probe')->firstOrFail()->update(['settings' => ['label' => 'Changed by staff']]);

        // The long job now keeps its own value.
        (fn () => $this->remember(['token' => 'secret-token']))->call($addon);

        $stored = Extension::query()->where('slug', 'probe')->firstOrFail();
        $this->assertSame(['label' => 'Changed by staff', 'token' => 'secret-token'], $stored->settings);
        $this->assertTrue($stored->is_enabled);
        $this->assertSame('secret-token', $extensions->settings('probe')['token']);
        $this->assertTrue($extensions->isEnabled('probe'));
    }

    public function test_staff_without_the_marketplace_permission_cannot_open_addon_pages(): void
    {
        $this->actingAs(Admin::factory()->withPermissions(['clients.view'])->create(), 'admin');

        $this->post(route('admin.addons.probe.connect'))->assertForbidden();
        $this->assertArrayNotHasKey('token', app(ExtensionManager::class)->settings('probe'));
    }

    public function test_addon_pages_are_locked_on_the_demo(): void
    {
        config(['nuvabill.demo' => true]);
        $this->actingAs(Admin::factory()->create(), 'admin');

        $this->post(route('admin.addons.probe.connect'))->assertSessionHas('error');
        $this->assertArrayNotHasKey('token', app(ExtensionManager::class)->settings('probe'));
    }

    public function test_addons_ship_their_own_translations(): void
    {
        app()->setLocale('ar');

        $this->assertSame('المسبار متصل.', __('Probe is connected.'));
    }
}
