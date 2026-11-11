<?php

namespace Tests\Feature;

use App\Contracts\HasResourceOptions;
use App\Enums\BillingCycle;
use App\Enums\MachineStatus;
use App\Enums\ServiceStatus;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\Resources\ResourceKind;
use App\Marketplace\LicenseChecker;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Machine;
use App\Models\MarketplaceInstall;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceResourceOption;
use App\Provisioning\Hardware\BmcException;
use App\Provisioning\Hardware\Drivers\PowerDrivers;
use App\Provisioning\Hardware\Tls\CertificateProbe;
use App\Provisioning\Hardware\Tls\PinningSupport;
use App\Support\Demo;
use Closure;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use PDO;
use RuntimeException;
use Tests\Fakes\FakePowerDriver;
use Tests\TestCase;

class DemoResetTest extends TestCase
{
    private const REFUSAL = 'This loads demo data and a public sign-in. It only runs on the demo or a local copy.';

    public function test_the_reset_only_runs_on_demo_sites(): void
    {
        $this->artisan('nuvabill:demo-reset')->assertFailed();
    }

    public function test_the_demo_data_does_not_load_on_a_real_site(): void
    {
        $database = storage_path('framework/testing/demo-data-real-site.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');
        config(['nuvabill.demo' => false]);
        $this->app['env'] = 'production';
        $this->useTemporaryDatabase($database);

        try {
            $this->assertSame(self::REFUSAL, $this->errorOf('migrate', ['--seed' => true, '--force' => true]));
            $this->assertSame(self::REFUSAL, $this->errorOf('db:seed', ['--force' => true]));

            // It stopped before anything changed: no shared sign-ins, no demo clients, the company keeps its name.
            $this->assertSame(0, Admin::query()->count());
            $this->assertSame(0, Client::query()->count());
            $this->assertSame(0, Product::query()->count());
            $this->assertFalse(DB::table('settings')->where('key', 'company.name')->exists());
        } finally {
            $this->backToTestDatabase();
            File::delete([$database, $database.'-journal']);
        }
    }

    public function test_the_demo_data_still_loads_on_the_demo_and_with_a_forced_reset(): void
    {
        $this->app['env'] = 'production';

        $this->inFreshDemo('demo-data-demo-site.sqlite', function (): void {
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());
        });

        $database = storage_path('framework/testing/demo-data-forced.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');
        config(['nuvabill.demo' => false]);
        $this->useTemporaryDatabase($database);

        try {
            $this->artisan('nuvabill:demo-reset', ['--force' => true])->assertSuccessful();
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());

            // The reset's own pass ends with it: a seed right after is refused again.
            $this->assertSame(self::REFUSAL, $this->errorOf('db:seed', ['--force' => true]));
        } finally {
            $this->backToTestDatabase();
            File::delete([$database, $database.'-journal', $database.'.next']);
        }
    }

    public function test_the_reset_swaps_in_a_database_full_of_demo_data(): void
    {
        $this->inFreshDemo('demo-reset.sqlite', function (string $database): void {
            $this->assertFileDoesNotExist($database.'.next');
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());
            $this->assertTrue(Client::query()->where('email', Demo::CLIENT_EMAIL)->exists());
            $this->assertTrue(Demo::hasData());
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
        });
    }

    public function test_the_demo_sells_resource_options_and_dedicated_servers(): void
    {
        $this->inFreshDemo('demo-reset-catalog.sqlite', function (): void {
            foreach (['vps-2', 'vps-4', 'vps-8'] as $slug) {
                $product = Product::query()->where('slug', $slug)->with('resourceOptions.prices')->sole();
                $this->assertSame(['cpu', 'ram', 'disk', 'ipv4'], $product->resourceOptions->pluck('kind')->all(), $slug);

                foreach ($product->resourceOptions as $option) {
                    $this->assertNotNull($option->priceFor('USD', BillingCycle::Monthly), "{$slug} {$option->kind}");
                    $this->assertNotNull($option->priceFor('USD', BillingCycle::Annually), "{$slug} {$option->kind}");
                }

                $disk = $product->resourceOptions->firstWhere('kind', 'disk');
                $this->assertFalse($disk->can_shrink);
                $this->assertFalse($disk->client_can_remove);

                // The kind details saved on each option are what the module declares, so the store
                // never shows other restart notes or maximums than the module works with.
                $kinds = $this->virtualizorKinds($product);

                foreach ($kinds === null ? [] : $product->resourceOptions as $option) {
                    $kind = $kinds[$option->kind] ?? null;
                    $this->assertNotNull($kind, "{$slug} {$option->kind}");
                    $this->assertSame([$kind->canShrink, $kind->restarts, $kind->maxExtra], [$option->can_shrink, $option->restarts, $option->max_extra], "{$slug} {$option->kind}");
                }
            }

            // The store shows the sliders and the dedicated servers without asking any server.
            $vps = Product::query()->where('slug', 'vps-2')->with('group')->sole();
            $this->get(route('store.product', [$vps->group, $vps]))->assertOk()->assertSee('Customize your server')->assertSee('The plan includes 4 GB.');

            // "Mer Las" has one more RAM step on a VPS, renewed at the price of one step.
            $ram = ServiceResourceOption::query()->with('service.client', 'service.product')->sole();
            $this->assertSame(['ram', 1, 1024, 300], [$ram->kind, $ram->steps, $ram->step_size, $ram->recurring_amount]);
            $this->assertSame(['Mer', 'Las'], [$ram->service->client->first_name, $ram->service->client->last_name]);
            $this->assertSame('virtualizor', $ram->service->product->server_module);

            $product = Product::query()->where('name', 'Dedicated E-2388G')->with('group')->sole();
            $this->assertSame('Dedicated servers', $product->group->name);
            $this->assertSame('dedicated', $product->server_module);
            $this->assertTrue($product->uses_machines);
            // Several free machines, so one visitor's order does not sell it out for everyone.
            $this->assertSame(4, $product->stockLeft());
            $this->get(route('store.group', $product->group))->assertOk()->assertSee('Dedicated E-2388G');

            $machines = Machine::query()->with('service.client', 'products')->get()->keyBy('name');
            $this->assertSame(['DS-101', 'DS-102', 'DS-103', 'DS-104', 'DS-105', 'DS-106'], $machines->keys()->sort()->values()->all());
            $this->assertSame(MachineStatus::Active, $machines['DS-101']->status);
            $this->assertSame(MachineStatus::Wiping, $machines['DS-103']->status);

            foreach (['DS-102', 'DS-104', 'DS-105', 'DS-106'] as $free) {
                $this->assertSame(MachineStatus::Available, $machines[$free]->status, $free);
            }

            // Every BMC is a TEST-NET address the demo fakes answer for, and no two machines share one.
            $this->assertSame(Demo::BMC_HOSTS, $machines->sortKeys()->pluck('bmc_host')->values()->all());
            $this->assertSame('192.0.2.21', $machines['DS-101']->bmc_host);
            $this->assertSame(ServiceStatus::Active, $machines['DS-101']->service->status);
            $this->assertSame(['Mer', 'Las'], [$machines['DS-101']->service->client->first_name, $machines['DS-101']->service->client->last_name]);
            $this->assertNotNull($machines['DS-101']->provisioned_at);

            foreach ($machines as $machine) {
                $this->assertTrue($machine->products->contains($product), $machine->name);
                $this->assertTrue($machine->bmcIsTrusted(), $machine->name);
                // The BMC login is kept encrypted, never as plain text.
                $raw = (string) DB::table('machines')->where('id', $machine->id)->value('bmc_password');
                $this->assertNotSame('', (string) $machine->bmc_password);
                $this->assertStringNotContainsString((string) $machine->bmc_password, $raw);
            }
        });
    }

    public function test_the_demo_server_panels_read_from_the_fake_servers(): void
    {
        $this->inFreshDemo('demo-reset-panels.sqlite', function (): void {
            $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => true, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
            FakePowerDriver::install();
            Demo::fakeServers();

            // The demo client's VPS shows every tab, read from the fake Virtualizor node.
            $client = Client::query()->where('email', Demo::CLIENT_EMAIL)->sole();
            $vps = $client->services()->whereHas('product', fn ($query) => $query->where('server_module', 'virtualizor'))->sole();
            $this->actingAs($client, 'web');

            $page = $this->get(route('client.services.show', $vps))->assertOk();

            foreach (['overview', 'console', 'graphs', 'reinstall', 'network', 'firewall', 'backups', 'rescue', 'ssh-keys', 'media', 'settings', 'activity'] as $key) {
                $page->assertSee('aria-controls="panel-'.$key.'"', false);
            }

            $graphs = $this->section($vps, 'graphs')->json('data');
            $this->assertSame(['cpu', 'ram', 'disk', 'net_in', 'net_out', 'io_read', 'io_write'], array_column($graphs['series'], 'key'));

            foreach ($graphs['series'] as $series) {
                $this->assertNotEmpty($series['points'], $series['key']);
            }

            $this->assertNotEmpty($graphs['bars']['points']);
            $this->assertCount(6, $graphs['period']['options']);

            $this->section($vps, 'overview')->assertJsonPath('data.state', 'running');
            $this->section($vps, 'network')->assertJsonPath('data.ips.0.rdns', $vps->domain);
            $this->section($vps, 'ssh-keys')->assertJsonPath('data.keys.0.name', 'Raz laptop');
            $this->section($vps, 'firewall')->assertJsonPath('data.plans.0.name', 'Web server')->assertJsonPath('data.plans.0.own', true);
            $this->section($vps, 'backups')->assertJsonCount(2, 'data.items');
            $this->section($vps, 'media')->assertJsonCount(2, 'data.isos');
            $this->section($vps, 'rescue')->assertJsonPath('data.enabled', false);
            $this->section($vps, 'activity')->assertJsonCount(2, 'data.tasks')->assertJsonCount(2, 'data.logs');

            // "Mer Las" sees the dedicated server's power state from its fake BMC, and none of the BMC details.
            $machine = Machine::query()->where('name', 'DS-101')->sole();
            $dedicated = $machine->service;
            $this->flushSession();
            $this->actingAs($dedicated->client, 'web');

            $this->get(route('client.services.show', $dedicated))->assertOk()
                ->assertSee('aria-controls="panel-network"', false)
                ->assertSee('aria-controls="panel-reinstall"', false);
            $overview = $this->section($dedicated, 'overview')->assertJsonPath('data.state', 'running');
            $this->assertStringNotContainsString('192.0.2.21', $overview->getContent());
            $this->assertStringNotContainsString('DS-101', $overview->getContent());

            // Staff see the power state of each machine from its fake BMC.
            $this->actingAs(Admin::query()->where('email', Demo::ADMIN_EMAIL)->sole(), 'admin');
            $this->get(route('admin.machines.index'))->assertOk()->assertSee('DS-103');
            $this->getJson(route('admin.machines.power.show', $machine))->assertOk()->assertJsonPath('state', 'on');
            $this->getJson(route('admin.machines.power.show', Machine::query()->where('name', 'DS-102')->sole()))->assertOk()->assertJsonPath('state', 'off');

            // Every call went to a demo fake, and none was a change.
            $hosts = Http::recorded()->map(fn (array $pair): string => (string) parse_url($pair[0]->url(), PHP_URL_HOST))->unique()->sort()->values()->all();
            $this->assertSame(['192.0.2.21', '192.0.2.22', Demo::VPS_HOST], $hosts);
            $this->assertEmpty(Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && ! str_contains($request->url(), 'act=users')));

            // A change that reaches the fake end-user panel anyway is refused, and a BMC certificate is never read.
            $this->assertSame(['This is turned off in the demo.'], Http::asForm()->post('https://'.Demo::VPS_HOST.':4083/index.php?act=rdns&svs=101&api=json', ['rdns' => 1])->json('error'));
            $this->expectException(BmcException::class);
            app(CertificateProbe::class)->fetch('192.0.2.21', 443, '192.0.2.21');
        });
    }

    public function test_the_demo_switches_on_the_chosen_addons_after_every_reset(): void
    {
        $this->useDemoExtensions();
        config(['nuvabill.demo_addons' => ' Demo-Power, not-here ,demo-nodb,../etc,demo-power']);

        $this->inFreshDemo('demo-reset-addons.sqlite', function (): void {
            $extensions = app(ExtensionManager::class);

            // Switched on with the settings the add-on asks for on a demo, and its own table made.
            $this->assertTrue($extensions->isEnabled('demo-power'));
            $this->assertSame(['allow_ipmi' => '0', 'health_alerts' => '0'], $extensions->settings('demo-power'));
            $this->assertTrue(Schema::hasTable('demo_power_readings'));

            // An add-on that changes the database without asking for the database permission stays off.
            $this->assertFalse($extensions->isEnabled('demo-nodb'));
            $this->assertFalse(Schema::hasTable('demo_nodb'));
            $this->assertFalse($extensions->isEnabled('not-here'));

            // The demo copy of a paid add-on gets no marketplace install and no license.
            $this->assertSame(0, MarketplaceInstall::query()->count());

            // With the add-on's power driver, staff read each demo machine's power from its fake BMC.
            $this->app->instance(PinningSupport::class, new PinningSupport(fn (string $function): bool => true, ['version' => '8.5.0', 'ssl_version' => 'OpenSSL/3.0.13']));
            $this->app->forgetInstance(PowerDrivers::class);
            $extensions->bootAddons();
            $this->assertNotNull(app(PowerDrivers::class)->get('redfish'));
            Demo::fakeServers();

            $this->actingAs(Admin::query()->where('email', Demo::ADMIN_EMAIL)->sole(), 'admin');
            $this->getJson(route('admin.machines.power.show', Machine::query()->where('name', 'DS-101')->sole()))->assertOk()->assertJsonPath('state', 'on');
            $this->getJson(route('admin.machines.power.show', Machine::query()->where('name', 'DS-104')->sole()))->assertOk()->assertJsonPath('state', 'off');
        });
    }

    public function test_a_broken_demo_addon_does_not_stop_the_reset(): void
    {
        $this->useDemoExtensions();
        config(['nuvabill.demo_addons' => 'demo-broken-migration,demo-power,demo-broken-class']);
        Log::spy();

        $this->inFreshDemo('demo-reset-broken.sqlite', function (): void {
            $extensions = app(ExtensionManager::class);

            // The demo still has fresh data, and the add-ons listed after the broken one are on.
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());
            $this->assertTrue($extensions->isEnabled('demo-power'));
            $this->assertTrue(Schema::hasTable('demo_power_readings'));

            // The add-on whose migration fails stays off. A table it made before failing is left as
            // it is, and the next reset starts from an empty database again.
            $this->assertFalse($extensions->isEnabled('demo-broken-migration'));

            // An add-on whose class cannot be made is never run, so it never reaches a visitor.
            $this->assertArrayNotHasKey('demo-broken-class', $extensions->activeAddons()->all());
            $this->assertSame(['demo-power'], $extensions->activeAddons()->keys()->all());
        });

        // The warning names the add-on only, never the error behind it.
        Log::shouldHaveReceived('warning')->with('The demo add-on demo-broken-migration could not be switched on.')->once();
    }

    public function test_a_second_reset_switches_the_addons_on_again(): void
    {
        $this->useDemoExtensions();
        config(['nuvabill.demo_addons' => 'demo-power']);

        $this->inFreshDemo('demo-reset-twice.sqlite', function (): void {
            // The live database now has the add-on and the gateways switched on, and those rows are
            // cached, as they are when the hourly cron boots the add-ons before the reset.
            $extensions = app(ExtensionManager::class);
            $this->assertTrue($extensions->isEnabled('demo-power'));
            $this->assertTrue($extensions->isEnabled('banktransfer'));

            $this->artisan('nuvabill:demo-reset')->assertSuccessful();
            DB::purge('sqlite');

            foreach (['demo-power', 'banktransfer', 'stripe', 'paypal'] as $slug) {
                $this->assertTrue((bool) DB::table('extensions')->where('slug', $slug)->value('is_enabled'), $slug);
            }

            $this->assertTrue(Schema::hasTable('demo_power_readings'));
        });
    }

    public function test_a_forced_reset_outside_demo_mode_switches_no_addons_on(): void
    {
        $this->useDemoExtensions();

        $database = storage_path('framework/testing/demo-reset-forced.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');

        config(['nuvabill.demo' => false, 'nuvabill.demo_addons' => 'demo-power']);
        $this->useTemporaryDatabase($database);

        try {
            $this->artisan('nuvabill:demo-reset', ['--force' => true])->assertSuccessful();

            $this->assertFalse(DB::table('extensions')->where('slug', 'demo-power')->exists());
            $this->assertFalse(app(ExtensionManager::class)->isEnabled('demo-power'));
            $this->assertFalse(Schema::hasTable('demo_power_readings'));
        } finally {
            $this->backToTestDatabase();
            File::delete([$database, $database.'-journal', $database.'.next']);
        }
    }

    public function test_demo_addons_are_only_read_in_demo_mode(): void
    {
        $this->useDemoExtensions();
        $database = storage_path('framework/testing/demo-reset-not-demo.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::put($database, '');
        config(['nuvabill.demo' => false, 'nuvabill.demo_addons' => 'demo-power']);
        $this->useTemporaryDatabase($database);

        try {
            $this->artisan('migrate')->assertSuccessful();

            $this->assertSame([], Demo::addons());

            // A real site still sees a paid add-on copied in by hand as unlicensed.
            $this->assertContains('demo-power', app(LicenseChecker::class)->unlicensedCopies()->pluck('slug')->all());

            // A seed outside demo mode switches nothing on.
            $this->seed(DatabaseSeeder::class);
            $this->assertFalse(app(ExtensionManager::class)->isEnabled('demo-power'));
            $this->assertFalse(Schema::hasTable('demo_power_readings'));

            config(['nuvabill.demo' => true]);
            $this->assertSame(['demo-power'], Demo::addons());
            config(['nuvabill.demo_addons' => '']);
            $this->assertSame([], Demo::addons());
        } finally {
            $this->backToTestDatabase();
            File::delete([$database, $database.'-journal']);
        }
    }

    public function test_the_fake_bmcs_report_health_and_power_use(): void
    {
        Demo::fakeServers();
        $bmc = fn (string $host, string $path): array => Http::get('https://'.$host.$path)->throw()->json();

        $system = $bmc(Demo::BMC_HOSTS[0], Demo::BMC_SYSTEM);
        $this->assertSame('OK', $system['Status']['HealthRollup']);
        $chassis = $bmc(Demo::BMC_HOSTS[0], $system['Links']['Chassis'][0]['@odata.id']);
        $thermal = $bmc(Demo::BMC_HOSTS[0], $chassis['Thermal']['@odata.id']);
        $this->assertCount(2, $thermal['Temperatures']);
        $this->assertCount(2, $thermal['Fans']);

        // A server that is on uses far more power than one that is off.
        $on = $bmc(Demo::BMC_HOSTS[0], $chassis['Power']['@odata.id']);
        $off = $bmc(Demo::BMC_HOSTS[1], $chassis['Power']['@odata.id']);
        $this->assertGreaterThan(80, $on['PowerControl'][0]['PowerConsumedWatts']);
        $this->assertLessThan(20, $off['PowerControl'][0]['PowerConsumedWatts']);
        $this->assertCount(2, $on['PowerSupplies']);

        $storage = $bmc(Demo::BMC_HOSTS[0], $system['Storage']['@odata.id']);
        $controller = $bmc(Demo::BMC_HOSTS[0], $storage['Members'][0]['@odata.id']);
        $this->assertSame('OK', $bmc(Demo::BMC_HOSTS[0], $controller['Drives'][1]['@odata.id'])['Status']['Health']);

        // The next boot device and the reset are accepted; anything else is not there.
        $this->assertSame(204, Http::patch('https://'.Demo::BMC_HOSTS[0].Demo::BMC_SYSTEM, ['Boot' => []])->status());
        $this->assertSame(404, Http::patch('https://'.Demo::BMC_HOSTS[0].'/redfish/v1/Managers/1', [])->status());
        $this->assertSame(404, Http::get('https://'.Demo::BMC_HOSTS[0].'/redfish/v1/Chassis/2')->status());
    }

    public function test_the_reset_waits_for_a_change_that_is_still_being_saved(): void
    {
        $database = storage_path('framework/testing/demo-reset-busy.sqlite');
        File::ensureDirectoryExists(dirname($database));
        File::delete([$database, $database.'-journal', $database.'.next']);

        // A visitor's change, half-way saved: SQLite keeps the old pages in a journal file meanwhile.
        $visitor = new PDO('sqlite:'.$database);
        $visitor->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $visitor->exec('create table visits (id integer primary key)');
        $visitor->exec('BEGIN IMMEDIATE');
        $visitor->exec('insert into visits default values');
        $this->assertFileExists($database.'-journal');

        config(['nuvabill.demo' => true]);
        $this->useTemporaryDatabase($database);

        try {
            $this->artisan('nuvabill:demo-reset', ['--wait' => 1])
                ->expectsOutputToContain('The demo database is busy')
                ->assertFailed();

            // Nothing was swapped under the visitor, so their journal still belongs to the old file.
            $this->assertFileDoesNotExist($database.'.next');
            $this->assertFileExists($database.'-journal');
            $visitor->exec('COMMIT');
            $this->assertSame(1, (int) $visitor->query('select count(*) from visits')->fetchColumn());
            $visitor = null;

            $this->artisan('nuvabill:demo-reset', ['--wait' => 1])->assertSuccessful();

            $this->assertFileDoesNotExist($database.'-journal');
            $this->assertSame('ok', DB::selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertTrue(Admin::query()->where('email', Demo::ADMIN_EMAIL)->exists());
        } finally {
            $visitor = null;
            $this->backToTestDatabase();
            File::delete([$database, $database.'-journal', $database.'.next']);
        }
    }

    /**
     * The error an Artisan command stops with, or null when it runs through.
     *
     * @param  array<string, mixed>  $options
     */
    private function errorOf(string $command, array $options): ?string
    {
        try {
            Artisan::call($command, $options);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }

        return null;
    }

    /**
     * Use a copy of the extensions folder with the test add-ons demo-power and demo-nodb in it.
     */
    private function useDemoExtensions(): void
    {
        $path = storage_path('framework/testing/demo-extensions-'.Str::lower(Str::random(8)));
        File::copyDirectory(base_path('extensions'), $path);
        File::copyDirectory(base_path('tests/Fixtures/demo-extensions'), $path);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($path));

        config(['nuvabill.extensions_path' => $path]);
        $this->app->forgetInstance(ExtensionManager::class);
    }

    /**
     * Run the demo reset into a new SQLite file, then the checks against the fresh demo data.
     *
     * @param  Closure(string): void  $checks  Given the database file.
     */
    /**
     * The connection the test started with, so a test that works on a temporary SQLite file leaves
     * the shared MySQL or MariaDB test database as it found it.
     *
     * @var array{0: string, 1: mixed}|null
     */
    private ?array $testDatabase = null;

    private function useTemporaryDatabase(string $database): void
    {
        $this->testDatabase ??= [(string) config('database.default'), config('database.connections.sqlite.database')];
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $database]);
        DB::purge('sqlite');
    }

    private function backToTestDatabase(): void
    {
        DB::purge('sqlite');

        if ($this->testDatabase !== null) {
            config(['database.default' => $this->testDatabase[0], 'database.connections.sqlite.database' => $this->testDatabase[1]]);
            $this->testDatabase = null;
        }
    }

    private function inFreshDemo(string $file, Closure $checks): void
    {
        $database = storage_path('framework/testing/'.$file);
        File::ensureDirectoryExists(dirname($database));
        File::put($database, 'old demo data');

        config(['nuvabill.demo' => true]);
        $this->useTemporaryDatabase($database);

        try {
            $this->artisan('nuvabill:demo-reset')->assertSuccessful();

            $checks($database);
        } finally {
            $this->backToTestDatabase();
            File::delete([$database, $database.'-journal', $database.'.next']);
        }
    }

    /**
     * The Virtualizor module's resource kinds for a demo VPS plan, or null while the module does not
     * build them yet. Any other failure fails the test.
     *
     * @return array<string, ResourceKind>|null
     */
    private function virtualizorKinds(Product $product): ?array
    {
        $module = app(ExtensionManager::class)->serverModule('virtualizor');
        $this->assertInstanceOf(HasResourceOptions::class, $module);

        try {
            return $module->resourceKinds($product);
        } catch (LogicException $exception) {
            if ($exception->getMessage() !== 'Not built yet.') {
                throw $exception;
            }

            return null;
        }
    }

    /**
     * One section of the client server panel, read as the browser reads it.
     */
    private function section(Service $service, string $section): TestResponse
    {
        return $this->getJson(route('client.services.sections.show', [$service, $section]))->assertOk();
    }
}
