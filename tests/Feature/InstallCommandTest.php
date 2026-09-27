<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Product;
use App\Support\Installation;
use App\Support\Installer;
use App\Support\Settings;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "php artisan nuvabill:install" in a temporary folder, so the project's own .env and database stay untouched.
 */
class InstallCommandTest extends TestCase
{
    private string $folder;

    private ?string $lockFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nuvabill-install-'.Str::random(8);
        mkdir($this->folder);
        $this->lockFile = is_file(Installation::lockPath()) ? file_get_contents(Installation::lockPath()) : null;

        config(['nuvabill.installed' => false]);
        $this->app->instance(Installer::class, new Installer($this->folder.DIRECTORY_SEPARATOR.'.env'));
    }

    protected function tearDown(): void
    {
        $this->lockFile === null ? @unlink(Installation::lockPath()) : file_put_contents(Installation::lockPath(), $this->lockFile);
        DB::purge('sqlite');
        File::deleteDirectory($this->folder);

        parent::tearDown();
    }

    public function test_it_installs_without_questions_and_makes_a_password(): void
    {
        $database = $this->folder.DIRECTORY_SEPARATOR.'nuvabill.sqlite';

        $this->artisan('nuvabill:install', [
            '--no-interaction' => true,
            '--url' => 'https://billing.example.test/',
            '--db' => 'sqlite',
            '--sqlite-path' => $database,
            '--company' => 'YourHost',
            '--company-email' => 'billing@example.test',
            '--currency' => 'iqd',
            '--name' => 'Mer Las',
            '--email' => 'mer@example.test',
        ])
            ->expectsOutputToContain('Nuvabill is installed.')
            ->expectsOutputToContain('https://billing.example.test/admin')
            ->expectsOutputToContain('Save this password now')
            ->expectsOutputToContain('artisan schedule:run')
            ->assertSuccessful();

        $env = Dotenv::parse(file_get_contents($this->folder.DIRECTORY_SEPARATOR.'.env'));
        $this->assertSame('https://billing.example.test', $env['APP_URL']);
        $this->assertSame('production', $env['APP_ENV']);
        $this->assertSame('sqlite', $env['DB_CONNECTION']);
        $this->assertSame($database, $env['DB_DATABASE']);
        $this->assertStringStartsWith('base64:', $env['APP_KEY']);

        config(['nuvabill.installed' => true]);
        app(Settings::class)->flush();

        $admin = Admin::query()->sole();
        $this->assertSame('Mer Las', $admin->name);
        $this->assertTrue($admin->role->isOwner());
        $this->assertSame('YourHost', setting('company.name'));
        $this->assertSame('IQD', setting('billing.currency'));
        $this->assertSame(0, Product::query()->count());
        $this->assertFileExists(Installation::lockPath());
    }

    public function test_it_asks_questions_in_the_terminal(): void
    {
        $this->artisan('nuvabill:install', ['--db' => 'sqlite', '--sqlite-path' => $this->folder.DIRECTORY_SEPARATOR.'nuvabill.sqlite'])
            ->expectsQuestion('Web address of this site', 'http://localhost:8000')
            ->expectsQuestion('Company name', 'YourHost')
            ->expectsQuestion('Billing email', 'billing@example.test')
            ->expectsQuestion('Your name', 'Raz')
            ->expectsQuestion('Your email', 'raz@example.test')
            ->expectsQuestion('Choose a password', 'a-long-password')
            ->expectsConfirmation('Add three example hosting plans to the store?', 'yes')
            ->expectsOutputToContain('Nuvabill is installed.')
            ->doesntExpectOutputToContain('Save this password now')
            ->assertSuccessful();

        $this->assertSame('Raz', Admin::query()->sole()->name);
        $this->assertSame(3, Product::query()->count());
    }

    public function test_it_explains_what_is_missing(): void
    {
        $this->artisan('nuvabill:install', ['--no-interaction' => true, '--db' => 'sqlite'])
            ->expectsOutputToContain('Add --url=')
            ->assertFailed();

        $this->artisan('nuvabill:install', ['--no-interaction' => true, '--url' => 'not a link', '--db' => 'sqlite', '--company' => 'YourHost'])
            ->assertFailed();

        $this->assertFileDoesNotExist($this->folder.DIRECTORY_SEPARATOR.'.env');
    }

    public function test_it_will_not_install_twice(): void
    {
        config(['nuvabill.installed' => true]);

        $this->artisan('nuvabill:install', ['--no-interaction' => true])
            ->expectsOutputToContain('already installed')
            ->assertFailed();
    }
}
