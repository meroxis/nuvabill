<?php

namespace Tests\Feature\Foundation;

use App\Enums\BillingCycle;
use App\Extensions\ExtensionManager;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ResourceOption;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Support\Demo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\PanelFake\PanelFakeModule;
use Tests\TestCase;

/**
 * The routes of resource options, the client server panel and dedicated hardware: each exists with
 * its method, permission and limit; each one that changes something or calls a server is locked in
 * the demo, while the two price checks stay open. Also the shared screens these routes hang off: the
 * product form, the service pages and the general settings.
 */
class RoutesAndLocksTest extends TestCase
{
    use RefreshDatabase;

    private const CLIENT_GROUP = ['web', 'auth:web', 'auth.session', 'client.active', 'client.two-factor'];

    private const ADMIN_GROUP = ['web', 'auth:admin', 'auth.session', 'admin.can', 'admin.two-factor'];

    /**
     * Settings routes that demo visitors may really use, by name. Empty: nothing under Settings is
     * saved in the demo.
     *
     * @var list<string>
     */
    private const SETTINGS_OPEN_IN_DEMO = [];

    /**
     * Every new route: method, path (admin paths without the admin prefix), the middleware it needs
     * besides its group's, and whether it is demo-locked.
     *
     * @return array<string, array{0: string, 1: string, 2: list<string>, 3: bool}>
     */
    public static function newRoutes(): array
    {
        return [
            'client.services.resources' => ['GET', 'client/services/{service}/resources', [], false],
            'client.services.resources.quote' => ['POST', 'client/services/{service}/resources/quote', ['throttle:60,1'], false],
            'client.services.resources.store' => ['POST', 'client/services/{service}/resources', ['throttle:10,1'], true],
            'client.services.resources.destroy' => ['DELETE', 'client/services/{service}/resources', ['throttle:10,1'], true],
            'client.services.sections.show' => ['GET', 'client/services/{service}/sections/{section}', ['throttle:panel-reads'], false],
            'client.services.sections.action' => ['POST', 'client/services/{service}/sections/{section}/{action}', ['throttle:panel-actions'], true],
            'client.services.console.open' => ['POST', 'client/services/{service}/console', ['throttle:10,1'], true],
            'client.services.console' => ['GET', 'client/services/{service}/console', ['throttle:30,1'], false],
            'client.services.console.redeem' => ['POST', 'client/services/{service}/console/redeem', ['throttle:20,1'], true],
            'admin.products.resources.index' => ['GET', 'products/{product}/resources', ['admin.can:products.manage'], false],
            'admin.products.resources.create' => ['GET', 'products/{product}/resources/create', ['admin.can:products.manage'], false],
            'admin.products.resources.store' => ['POST', 'products/{product}/resources', ['admin.can:products.manage', 'throttle:30,1'], true],
            'admin.products.resources.edit' => ['GET', 'products/{product}/resources/{resourceOption}/edit', ['admin.can:products.manage'], false],
            'admin.products.resources.update' => ['PUT', 'products/{product}/resources/{resourceOption}', ['admin.can:products.manage', 'throttle:30,1'], true],
            'admin.products.resources.destroy' => ['DELETE', 'products/{product}/resources/{resourceOption}', ['admin.can:products.manage'], true],
            'admin.products.resources.order' => ['POST', 'products/{product}/resources/order', ['admin.can:products.manage'], true],
            'admin.products.resources.base' => ['POST', 'products/{product}/resources/base', ['admin.can:products.manage', 'throttle:10,1'], true],
            'admin.products.resources.old-addons.destroy' => ['DELETE', 'products/{product}/resources/old-addons', ['admin.can:products.manage', 'throttle:10,1'], true],
            'admin.services.resources.quote' => ['POST', 'services/{service}/resources/quote', ['admin.can:services.manage', 'throttle:60,1'], false],
            'admin.services.resources.change' => ['POST', 'services/{service}/resources', ['admin.can:services.manage', 'throttle:10,1'], true],
            'admin.services.resources.cancel' => ['DELETE', 'services/{service}/resources', ['admin.can:services.manage'], true],
            'admin.services.resources.apply' => ['POST', 'services/{service}/resources/apply', ['admin.can:services.manage', 'throttle:6,1'], true],
            'admin.services.resources.check' => ['POST', 'services/{service}/resources/check', ['admin.can:services.manage', 'throttle:6,1'], true],
            'admin.services.resources.retry' => ['POST', 'services/{service}/resources/retry', ['admin.can:services.manage', 'throttle:6,1'], true],
            'admin.services.resources.done' => ['POST', 'services/{service}/resources/done', ['admin.can:services.manage'], true],
            'admin.services.access-check' => ['POST', 'services/{service}/access-check', ['admin.can:services.manage', 'throttle:10,1'], true],
            'admin.services.link' => ['POST', 'services/{service}/link', ['admin.can:services.manage', 'throttle:10,1'], true],
            'admin.machines.index' => ['GET', 'hardware', ['admin.can:hardware.power'], false],
            'admin.machines.show' => ['GET', 'hardware/{machine}', ['admin.can:hardware.power'], false],
            'admin.machines.power.show' => ['GET', 'hardware/{machine}/power', ['admin.can:hardware.power', 'throttle:30,1'], false],
            'admin.machines.power' => ['POST', 'hardware/{machine}/power/{action}', ['admin.can:hardware.power', 'throttle:20,1'], true],
            'admin.machines.create' => ['GET', 'hardware/create', ['admin.can:hardware.manage'], false],
            'admin.machines.store' => ['POST', 'hardware', ['admin.can:hardware.manage', 'throttle:20,1'], true],
            'admin.machines.edit' => ['GET', 'hardware/{machine}/edit', ['admin.can:hardware.manage'], false],
            'admin.machines.update' => ['PUT', 'hardware/{machine}', ['admin.can:hardware.manage', 'throttle:20,1'], true],
            'admin.machines.destroy' => ['DELETE', 'hardware/{machine}', ['admin.can:hardware.manage'], true],
            'admin.machines.test' => ['POST', 'hardware/{machine}/test', ['admin.can:hardware.manage', 'throttle:10,1'], true],
            'admin.machines.trust' => ['POST', 'hardware/{machine}/trust', ['admin.can:hardware.manage', 'throttle:10,1'], true],
            'admin.machines.status' => ['POST', 'hardware/{machine}/status', ['admin.can:hardware.manage', 'throttle:20,1'], true],
            'admin.machines.wiped' => ['POST', 'hardware/{machine}/wiped', ['admin.can:hardware.manage', 'throttle:20,1'], true],
            'admin.machines.settings' => ['PUT', 'hardware/settings', ['admin.can:hardware.manage', 'admin.can:settings.manage'], true],
            'admin.services.machine.assign' => ['POST', 'services/{service}/machine', ['admin.can:services.manage', 'throttle:20,1'], true],
            'admin.services.machine.ready' => ['POST', 'services/{service}/machine/ready', ['admin.can:services.manage', 'throttle:10,1'], true],
            'admin.services.machine.replace' => ['POST', 'services/{service}/machine/replace', ['admin.can:services.manage', 'admin.can:hardware.manage', 'throttle:10,1'], true],
            'admin.services.staff-action' => ['POST', 'services/{service}/staff/{action}', ['admin.can:services.manage', 'admin.two-factor-on', 'throttle:10,1'], true],
            'admin.two-factor.step-up' => ['GET', 'two-factor/confirm', [], false],
            'admin.profile.two-factor.start' => ['POST', 'profile/two-factor', ['throttle:staff-reauth'], true],
            'admin.profile.two-factor.confirm' => ['POST', 'profile/two-factor/confirm', ['throttle:staff-reauth'], true],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function lockedRoutes(): array
    {
        return collect(self::newRoutes())->filter(fn (array $route): bool => $route[3])->map(fn (array $route, string $name): array => [$name])->all();
    }

    /**
     * @param  list<string>  $middleware
     */
    #[DataProvider('newRoutesWithDetails')]
    public function test_every_new_route_exists_with_its_method_permission_and_limit(string $name, string $method, string $uri, array $middleware): void
    {
        $route = Route::getRoutes()->getByName($name);

        $this->assertInstanceOf(RoutingRoute::class, $route, "{$name} exists.");
        $this->assertContains($method, $route->methods(), "{$name} answers {$method}.");

        $admin = str_starts_with($name, 'admin.');
        $this->assertSame($admin ? trim((string) config('nuvabill.admin_path'), '/').'/'.$uri : $uri, $route->uri());

        foreach ([...($admin ? self::ADMIN_GROUP : self::CLIENT_GROUP), ...$middleware] as $expected) {
            $this->assertContains($expected, $route->middleware(), "{$name} has {$expected}.");
        }
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: list<string>}>
     */
    public static function newRoutesWithDetails(): array
    {
        return collect(self::newRoutes())->map(fn (array $route, string $name): array => [$name, $route[0], $route[1], $route[2]])->all();
    }

    public function test_route_parameters_only_take_safe_values(): void
    {
        $routes = Route::getRoutes();

        $this->assertSame('[a-z0-9-]{1,32}', $routes->getByName('client.services.sections.show')->wheres['section']);
        $this->assertSame('[a-z0-9-]{1,32}', $routes->getByName('client.services.sections.action')->wheres['section']);
        $this->assertSame('[a-z0-9-]{1,32}', $routes->getByName('client.services.sections.action')->wheres['action']);
        $this->assertSame('on|shutdown|restart|reset|off', $routes->getByName('admin.machines.power')->wheres['action']);

        foreach (['show', 'power.show', 'power', 'edit', 'update', 'destroy', 'test', 'trust', 'status', 'wiped'] as $machineRoute) {
            $this->assertSame('[0-9]+', $routes->getByName("admin.machines.{$machineRoute}")->wheres['machine'], "admin.machines.{$machineRoute} takes only a number.");
        }

        // An option is only found inside its own product.
        foreach (['edit', 'update', 'destroy'] as $optionRoute) {
            $route = $routes->getByName("admin.products.resources.{$optionRoute}");
            $this->assertSame('[0-9]+', $route->wheres['resourceOption']);
            $this->assertTrue($route->enforcesScopedBindings(), "admin.products.resources.{$optionRoute} is scoped to its product.");
        }
    }

    public function test_the_demo_lock_list_holds_every_changing_route_and_not_the_price_checks(): void
    {
        foreach (self::newRoutes() as $name => [$method, $uri, $middleware, $locked]) {
            $this->assertSame($locked, $this->isLockedInDemo($name), $locked ? "{$name} is locked in the demo." : "{$name} stays open in the demo.");
        }

        $this->assertFalse($this->isLockedInDemo('client.services.resources.quote'));
        $this->assertFalse($this->isLockedInDemo('admin.services.resources.quote'));
    }

    /**
     * Every staff route for settings that saves, deletes or sends something is either locked in the
     * demo or named in SETTINGS_OPEN_IN_DEMO. That is everything under Settings, and the settings of
     * other pages (such as admin.kb.settings). A new settings route in neither list fails here, so
     * it is never left open by accident.
     */
    public function test_every_settings_route_that_changes_something_is_locked_in_the_demo_or_listed_as_open(): void
    {
        $adminPath = trim((string) config('nuvabill.admin_path'), '/');
        $checked = 0;
        $unlisted = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();
            $changing = array_values(array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']));
            $isStaffRoute = str_starts_with($name, 'admin.') || str_starts_with($route->uri().'/', $adminPath.'/');
            $isSettings = in_array('settings', explode('.', $name), true) || in_array('settings', explode('/', $route->uri()), true);

            if ($changing === [] || ! $isStaffRoute || ! $isSettings) {
                continue;
            }

            $checked++;
            $locked = $name !== '' && $this->demoLocks($route, $changing[0]);
            $open = in_array($name, self::SETTINGS_OPEN_IN_DEMO, true);

            // In exactly one list: a route without a name can be in neither.
            if ($locked === $open) {
                $unlisted[] = implode('|', $changing).' '.$route->uri().' ('.($name ?: 'no name').')';
            }
        }

        $this->assertGreaterThan(50, $checked, 'The settings routes were found.');
        $this->assertSame([], $unlisted, 'Add each of these settings routes to Demo::LOCKED_ROUTES, or to SETTINGS_OPEN_IN_DEMO when demo visitors may really use it.');

        foreach (self::SETTINGS_OPEN_IN_DEMO as $name) {
            $this->assertTrue(Route::has($name), "{$name} is listed as open in the demo, but there is no such route.");
        }
    }

    public function test_email_templates_departments_and_domain_prices_are_locked_in_the_demo(): void
    {
        foreach (['email-templates.update', 'departments.store', 'departments.update', 'tlds.store', 'tlds.settings', 'tlds.update', 'tlds.destroy'] as $route) {
            $this->assertTrue($this->isLockedInDemo("admin.settings.{$route}"), "admin.settings.{$route} is locked in the demo.");
        }

        // The pages themselves still open.
        foreach (['email-templates.index', 'email-templates.edit', 'departments.index', 'tlds.index', 'tlds.edit'] as $route) {
            $this->assertFalse($this->isLockedInDemo("admin.settings.{$route}"), "admin.settings.{$route} stays open in the demo.");
        }
    }

    #[DataProvider('lockedRoutes')]
    public function test_every_locked_route_answers_403_in_the_demo(string $name): void
    {
        config(['nuvabill.demo' => true]);
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test']);
        $product = Product::factory()->priced()->create();
        $service = Service::factory()->create(['client_id' => $client->id, 'product_id' => $product->id]);
        $option = ResourceOption::factory()->create(['product_id' => $product->id]);
        $machine = Machine::factory()->create();

        $this->actingAs($client, 'web');
        $this->actingAs(Admin::factory()->create(['name' => 'Raz']), 'admin');

        $parameters = match (true) {
            $name === 'client.services.sections.action' => [$service, 'power', 'start'],
            $name === 'admin.machines.power' => [$machine, 'off'],
            $name === 'admin.services.staff-action' => [$service, 'migrate'],
            $name === 'admin.machines.store', $name === 'admin.machines.settings' => [],
            str_starts_with($name, 'admin.machines.') => [$machine],
            str_starts_with($name, 'admin.products.resources.') => in_array($name, ['admin.products.resources.update', 'admin.products.resources.destroy'], true) ? [$product, $option] : [$product],
            default => [$service],
        };

        $this->json(self::newRoutes()[$name][0], route($name, $parameters))
            ->assertForbidden()
            ->assertExactJson(['message' => __('This is turned off in the demo, so it keeps working for every visitor.')]);
    }

    public function test_the_product_form_saves_only_the_sections_the_module_declares_and_the_hardware_flag(): void
    {
        $this->useFixtureModules();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $server = Server::factory()->create(['module' => 'panelfake']);

        // The hardware module gets no server choice: only the other module lists the servers.
        $this->get(route('admin.products.create'))->assertOk()
            ->assertDontSee('f-server_id-panelfake')
            ->assertSee('f-server_id-formpanel');

        $this->post(route('admin.products.store'), $this->productForm([
            'server_module' => 'panelfake',
            'server_id' => $server->id,
            'stock' => '5',
            'panel_sections' => ['overview' => '0', 'graphs' => '1', 'made-up' => '1', 'power' => '1'],
        ]))->assertSessionHasNoErrors();

        $product = Product::query()->where('slug', 'fixture-plan')->firstOrFail();
        // Unknown keys are dropped; sections that were not posted get the module's default (snapshots is off by default).
        $this->assertSameSections([
            'overview' => false, 'power' => true, 'console' => true, 'graphs' => true, 'network' => true,
            'reinstall' => true, 'settings' => true, 'snapshots' => false,
        ], $product->panel_sections);
        // A hardware module: stock comes from free machines, and there is no server.
        $this->assertTrue($product->uses_machines);
        $this->assertNull($product->stock);
        $this->assertNull($product->server_id);

        // Switches that are not posted keep what staff saved.
        $this->put(route('admin.products.update', $product), $this->productForm([
            'server_module' => 'panelfake',
            'panel_sections' => ['graphs' => '0', 'snapshots' => '1'],
        ]))->assertSessionHasNoErrors();
        $this->assertSameSections([
            'overview' => false, 'power' => true, 'console' => true, 'graphs' => false, 'network' => true,
            'reinstall' => true, 'settings' => true, 'snapshots' => true,
        ], $product->fresh()->panel_sections);

        // A module without panel sections or machines.
        $this->put(route('admin.products.update', $product), $this->productForm([
            'server_module' => 'formpanel',
            'stock' => '5',
            'panel_sections' => ['overview' => '1'],
        ]))->assertSessionHasNoErrors();
        $product->refresh();
        $this->assertNull($product->panel_sections);
        $this->assertFalse($product->uses_machines);
        $this->assertSame(5, $product->stock);
    }

    public function test_a_product_whose_services_hold_machines_keeps_its_module(): void
    {
        $this->useFixtureModules();
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $product = Product::factory()->priced()->create(['slug' => 'fixture-plan', 'server_module' => 'panelfake', 'uses_machines' => true]);
        $service = Service::factory()->create(['product_id' => $product->id, 'client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las'])->id]);
        Machine::factory()->activeFor($service)->create();

        $this->put(route('admin.products.update', $product), $this->productForm(['server_module' => 'formpanel']))
            ->assertSessionHasErrors(['server_module' => 'This product has servers in use, so its module cannot change.']);
        $this->assertSame('panelfake', $product->fresh()->server_module);

        // Other changes still save.
        $this->put(route('admin.products.update', $product), $this->productForm(['server_module' => 'panelfake', 'name' => 'Fixture plan XL']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Fixture plan XL', $product->fresh()->name);
    }

    public function test_the_general_settings_save_the_resource_and_server_panel_choices(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));

        $this->get(route('admin.settings.edit'))->assertOk()
            ->assertSee('Clients can add and remove resources')
            ->assertSee('Client server panel')
            ->assertSee('Refresh live figures every (seconds)');

        $this->put(route('admin.settings.update'), $this->generalSettings([
            'resource_changes' => '0',
            'panel_console' => '0',
            'panel_live_seconds' => '30',
            'panel_change_emails' => '0',
        ]))->assertSessionHas('status');

        $this->assertFalse(setting('billing.resource_changes'));
        $this->assertFalse(setting('services.panel_console'));
        $this->assertSame(30, setting('services.panel_live_seconds'));
        $this->assertFalse(setting('services.panel_change_emails'));

        $this->put(route('admin.settings.update'), $this->generalSettings(['panel_live_seconds' => '2']))
            ->assertSessionHasErrors('panel_live_seconds');
        $this->assertSame(30, setting('services.panel_live_seconds'));

        // A request without the fields keeps the saved choices.
        $this->put(route('admin.settings.update'), $this->generalSettings([]))->assertSessionHas('status');
        $this->assertFalse(setting('billing.resource_changes'));
        $this->assertFalse(setting('services.panel_console'));
        $this->assertSame(30, setting('services.panel_live_seconds'));
    }

    public function test_the_client_service_page_shows_the_extras_and_what_each_renewal_costs(): void
    {
        $client = Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las', 'email' => 'mer.las@example.test']);
        $service = Service::factory()->create(['client_id' => $client->id, 'recurring_amount' => 1000]);
        $option = ResourceOption::factory()->create(['product_id' => $service->product_id, 'label' => 'Memory']);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => $option->id, 'kind' => 'ram', 'unit' => 'MB', 'step_size' => 1024, 'steps' => 2, 'recurring_amount' => 400]);
        ServiceResourceOption::factory()->cancelled()->create(['service_id' => $service->id, 'resource_option_id' => null, 'kind' => 'ipv4', 'unit' => 'IP', 'step_size' => 1]);

        $this->actingAs($client, 'web')
            ->get(route('client.services.show', $service))
            ->assertOk()
            ->assertSeeInOrder(['Billing', '$14.00'])
            ->assertSee('Memory +2 GB')
            ->assertSee('$4.00')
            ->assertDontSee(ResourceOption::kindLabel('ipv4'));
    }

    public function test_staff_are_warned_to_check_extra_prices_after_a_billing_cycle_change(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz']));
        $service = Service::factory()->create(['client_id' => Client::factory()->create(['first_name' => 'Mer', 'last_name' => 'Las'])->id]);
        ServiceResourceOption::factory()->create(['service_id' => $service->id, 'resource_option_id' => null, 'kind' => 'ram', 'unit' => 'MB', 'step_size' => 1024, 'steps' => 1, 'recurring_amount' => 250]);

        $this->get(route('admin.services.show', $service))->assertOk()
            ->assertSee('Plan renewal price (USD)')
            ->assertSee('Extras are billed on their own lines: $2.50.');

        $form = ['status' => 'active', 'recurring_amount' => '10.00', 'next_due_date' => $service->next_due_date->toDateString()];

        $this->put(route('admin.services.update', $service), $form + ['billing_cycle' => BillingCycle::Monthly->value])
            ->assertSessionHas('status')
            ->assertSessionMissing('warning');

        $this->put(route('admin.services.update', $service), $form + ['billing_cycle' => BillingCycle::Annually->value])
            ->assertSessionHas('warning', 'Check the resource prices of this service: they are for the old billing cycle.');
    }

    /**
     * Whether the demo turns the route away, matched the way ProtectDemo matches it: pages that only
     * read (GET) always open.
     */
    private function isLockedInDemo(string $name): bool
    {
        $route = Route::getRoutes()->getByName($name);
        $request = Request::create('/', $route->methods()[0]);
        $request->setRouteResolver(fn () => $route);

        return ! $request->isMethodSafe() && $request->routeIs(...Demo::LOCKED_ROUTES);
    }

    /**
     * Whether the demo turns away a request to the route with this method.
     */
    private function demoLocks(RoutingRoute $route, string $method): bool
    {
        $request = Request::create('/', $method);
        $request->setRouteResolver(fn () => $route);

        return ! $request->isMethodSafe() && $request->routeIs(...Demo::LOCKED_ROUTES);
    }

    private function useFixtureModules(): void
    {
        config(['nuvabill.extensions_path' => base_path('tests/Fixtures/extensions')]);
        app()->forgetInstance(ExtensionManager::class);
        app(ExtensionManager::class)->manifests();
        PanelFakeModule::reset();
    }

    /**
     * The saved panel switches, without their order: MySQL's JSON type keeps object keys in its own
     * order, while SQLite and MariaDB keep them as saved.
     *
     * @param  array<string, bool>  $expected
     */
    private function assertSameSections(array $expected, mixed $actual): void
    {
        $this->assertIsArray($actual);
        ksort($expected);
        ksort($actual);
        $this->assertSame($expected, $actual);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function productForm(array $overrides): array
    {
        return $overrides + [
            'product_group_id' => ProductGroup::query()->value('id') ?? ProductGroup::factory()->create()->id,
            'name' => 'Fixture plan',
            'slug' => 'fixture-plan',
            'type' => 'server',
            'auto_setup' => 'payment',
            'prices' => ['monthly' => ['enabled' => '1', 'price' => '20.00', 'setup_fee' => '0']],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function generalSettings(array $overrides): array
    {
        return $overrides + [
            'company_name' => 'YourHost',
            'company_email' => 'billing@host.test',
            'currency' => 'USD',
            'renewal_days_before' => 7,
            'payment_terms_days' => 7,
            'suspend_days' => 5,
            'terminate_days' => 30,
            'accent' => '#0B7A70',
            'theme' => 'nova',
            'locale_default' => 'en',
            'locale_enabled' => ['en'],
        ];
    }
}
