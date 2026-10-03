<?php

namespace Tests\Feature;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Support\Installer;
use Database\Seeders\DemoCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class InstallerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_uses_the_default_database_file_when_the_installer_leaves_the_name_empty(): void
    {
        $original = [$_SERVER['DB_DATABASE'] ?? null, $_ENV['DB_DATABASE'] ?? null];

        try {
            foreach (['null', ''] as $value) {
                $_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'] = $value;
                $config = require config_path('database.php');

                $this->assertSame(database_path('database.sqlite'), $config['connections']['sqlite']['database']);
            }
        } finally {
            [$_SERVER['DB_DATABASE'], $_ENV['DB_DATABASE']] = $original;
        }
    }

    public function test_a_fresh_copy_sends_visitors_to_the_installer(): void
    {
        config(['nuvabill.installed' => false]);

        $this->get(route('store.index'))->assertRedirect(route('install.welcome'));
        $this->get(route('admin.login'))->assertRedirect(route('install.welcome'));
        $this->get(route('install.welcome'))->assertOk()->assertSee('Check your server');
    }

    public function test_the_web_installer_sends_the_form_to_the_installer_steps(): void
    {
        config(['nuvabill.installed' => false]);
        $installer = new class extends Installer
        {
            /** @var array<string, mixed>|null */
            public ?array $database = null;

            public function setUpDatabase(array $data): void
            {
                if ($data['host'] === 'wrong.example.test') {
                    throw new RuntimeException('Could not connect to the database: refused');
                }

                $this->database = $data;
            }
        };
        $this->app->instance(Installer::class, $installer);

        $form = ['app_url' => 'https://billing.example.test', 'driver' => 'mysql', 'host' => 'wrong.example.test', 'port' => 3306, 'database' => 'nuvabill', 'username' => 'nuvabill', 'password' => 'secret'];

        $this->post(route('install.database.save'), $form)->assertSessionHasErrors(['host' => 'Could not connect to the database: refused']);
        $this->post(route('install.database.save'), ['host' => 'db.example.test'] + $form)->assertRedirect(route('install.account'));
        $this->assertSame('db.example.test', $installer->database['host']);
        $this->assertSame('secret', $installer->database['password']);
    }

    public function test_the_installer_is_closed_after_installation(): void
    {
        $this->get(route('install.welcome'))->assertNotFound();
        $this->get(route('install.account'))->assertNotFound();
    }

    public function test_example_plans_in_a_currency_without_usd_like_amounts_are_not_sold_automatically(): void
    {
        (new DemoCatalogSeeder)->run('IQD');

        $plans = Product::query()->whereIn('slug', ['starter', 'business', 'pro'])->get();

        $this->assertCount(3, $plans);
        $this->assertFalse(Product::query()->visible()->whereIn('slug', ['starter', 'business', 'pro'])->exists());
        $this->assertFalse(ProductGroup::query()->where('slug', 'web-hosting')->sole()->is_visible);
        $plans->each(fn (Product $plan) => $this->assertSame(AutoSetup::Manual, $plan->auto_setup));
    }

    public function test_the_installer_says_which_currencies_keep_the_example_plans_on_sale(): void
    {
        config(['nuvabill.installed' => false]);
        $this->app->instance(Installer::class, new class extends Installer
        {
            public function databaseIsReady(): bool
            {
                return true;
            }
        });

        $help = 'Three sample plans you can edit or delete. Their prices fit USD, EUR, GBP, CAD, AUD, NZD, CHF and SGD. In other currencies they stay hidden until you set their prices.';

        $this->get(route('install.account'))->assertOk()->assertSee($help);

        // The help text names every currency the plans stay on sale in.
        foreach (DemoCatalogSeeder::PRICED_FOR as $currency) {
            $this->assertStringContainsString($currency, $help);
        }
    }

    public function test_example_plans_in_usd_stay_on_sale(): void
    {
        (new DemoCatalogSeeder)->run('USD');

        $plans = Product::query()->visible()->whereIn('slug', ['starter', 'business', 'pro'])->get();

        $this->assertCount(3, $plans);
        $plans->each(fn (Product $plan) => $this->assertSame(AutoSetup::OnPayment, $plan->auto_setup));
        $this->assertSame(399, $plans->firstWhere('slug', 'starter')->priceFor('USD', BillingCycle::Monthly)->price);
    }
}
