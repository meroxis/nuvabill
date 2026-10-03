<?php

namespace Tests\Feature\Admin;

use App\Health\CheckResult;
use App\Health\Checks\DatabaseSafetyChecks;
use App\Health\Checks\FileChecks;
use App\Health\Checks\SearchSetupChecks;
use App\Health\Checks\SettingsChecks;
use App\Health\CoreFiles;
use App\Health\DatabaseInspector;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Models\HealthRun;
use App\Support\Cloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresFunction;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use Tests\TestCase;

/**
 * Site health checks of the server set-up: debug mode, the proxy, the database, file permissions
 * and the addresses the store opens at.
 */
class SiteHealthSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    public function test_debug_mode_on_a_public_address_is_urgent_even_with_app_env_local(): void
    {
        config(['app.debug' => true, 'app.url' => 'https://billing.example-host.com']);
        $this->app['env'] = 'local';

        $public = $this->settingsCheck('settings.debug');
        $this->assertSame(Status::Urgent, $public->status);
        $this->assertSame('settings.debug_off', $public->fix['action']);

        config(['app.url' => 'http://nuvabill.test']);
        $this->assertSame(Status::Passed, $this->settingsCheck('settings.debug')->status);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function grantLines(): array
    {
        return [
            'MariaDB password and unix socket' => ["GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` IDENTIFIED VIA mysql_native_password USING '*81F5E21E35407D884A6CD4A731AEBFB6AF209E1B' OR unix_socket WITH GRANT OPTION"],
            'MariaDB ed25519' => ["GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` IDENTIFIED VIA ed25519 USING 'ZIgUREUg5PVgQ6LskhXmO+eZLS0nC8be6HPjYWR4YJY'"],
            'Password hash' => ["GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost` IDENTIFIED BY PASSWORD '*81F5E21E35407D884A6CD4A731AEBFB6AF209E1B' WITH GRANT OPTION"],
            'MySQL 8' => ['GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost`'],
        ];
    }

    #[DataProvider('grantLines')]
    public function test_database_grants_are_shown_without_the_password_hash(string $grant): void
    {
        $label = DatabaseSafetyChecks::withoutCredentials($grant);

        $this->assertSame('GRANT ALL PRIVILEGES ON *.* TO `root`@`localhost`', $label);
        $this->assertStringNotContainsString('USING', $label);
        $this->assertStringNotContainsString('81F5E21E', $label);
    }

    public function test_the_proxy_check_needs_cloudflare_ranges_to_be_trusted(): void
    {
        config(['app.url' => 'https://billing.example-host.com']);
        $this->setSettings(['health.outside_check' => true]);
        Http::fake([
            'billing.example-host.com/' => Http::response('<html></html>', 200, [
                'CF-RAY' => 'abc123-AMS',
                'Server' => 'cloudflare',
                'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'Strict-Transport-Security' => 'max-age=31536000',
            ]),
            '*' => Http::response('Not found', 404),
        ]);

        config(['trustedproxy.proxies' => ['127.0.0.1']]);
        $this->assertSame(Status::Warning, app(SiteHealth::class)->run()->check('outside.proxy')->status);

        config(['trustedproxy.proxies' => Cloudflare::IP_RANGES]);
        $this->assertSame(Status::Passed, app(SiteHealth::class)->run()->check('outside.proxy')->status);

        config(['trustedproxy.proxies' => null]);
        $this->assertSame(Status::Warning, app(SiteHealth::class)->run()->check('outside.proxy')->status);
    }

    public function test_table_names_are_only_those_of_nuvabills_database(): void
    {
        DB::statement('create temporary table foreign_probe (id integer)');

        $names = app(DatabaseInspector::class)->tableNames();

        $this->assertContains('invoices', $names);
        $this->assertNotContains('foreign_probe', $names);
    }

    public function test_table_names_on_mysql_ask_for_the_current_database_only(): void
    {
        if (! extension_loaded('pdo_mysql')) {
            $this->markTestSkipped('The pdo_mysql extension is not installed.');
        }

        config(['database.connections.mysql.database' => 'nuvabill_test']);
        DB::purge('mysql');
        $mysql = DB::connection('mysql');
        $this->partialMock(DatabaseInspector::class, fn (MockInterface $mock) => $mock->shouldReceive('connection')->andReturn($mysql));

        $queries = $mysql->pretend(fn () => app(DatabaseInspector::class)->tableNames());
        $sql = implode("\n", array_column($queries, 'query'));

        $this->assertStringContainsString("table_schema in ('nuvabill_test')", $sql);
        $this->assertStringNotContainsString("not in ('information_schema'", $sql);
    }

    public function test_a_trailing_dot_host_is_not_another_address(): void
    {
        config(['app.url' => 'https://billing.nuvabill.com']);

        $this->get('https://billing.nuvabill.com./')->assertOk();
        $this->assertFalse(Cache::has(SearchSetupChecks::OTHER_HOSTS_KEY));

        // A value kept by an earlier version passes too.
        Cache::put('seo.other_host', 'billing.nuvabill.com.', now()->addDay());
        $this->assertSame(Status::Passed, $this->searchCheck('seo.one_address')->status);
    }

    public function test_a_made_up_address_does_not_hide_a_real_second_address(): void
    {
        config(['app.url' => 'https://billing.nuvabill.com']);

        $this->get('https://other.nuvabill.com/')->assertOk();
        $this->get('https://www.billing.nuvabill.com/')->assertOk();

        $this->assertEqualsCanonicalizing(['other.nuvabill.com', 'www.billing.nuvabill.com'], array_keys(Cache::get(SearchSetupChecks::OTHER_HOSTS_KEY)));

        $check = $this->searchCheck('seo.one_address');
        $this->assertSame(Status::Warning, $check->status);
        $this->assertStringContainsString('www.billing.nuvabill.com', $check->params['other']);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function serverSettings(): array
    {
        return [
            'Laravel rewrite rules' => [(string) file_get_contents(dirname(__DIR__, 3).'/public/.htaccess'), false],
            'cPanel PHP version' => ["<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php84 .php .php8 .phtml\n</IfModule>", false],
            'LiteSpeed PHP files' => ["<FilesMatch \"\\.(php4|php5|php3|php2|php|phtml)$\">\nSetHandler application/x-lsphp74\n</FilesMatch>", false],
            'PHP settings' => ["memory_limit = 256M\nauto_prepend_file = none", false],
            'Prepended file' => ['php_value auto_prepend_file /home/someone/public_html/images/logo.png', true],
            'Images run as PHP' => ['AddType application/x-httpd-php .png .jpg', true],
            'Handler for images' => ["<FilesMatch \"\\.(png|php)$\">\nSetHandler application/x-httpd-php\n</FilesMatch>", true],
            'Handler for the whole folder' => ['SetHandler application/x-httpd-php', true],
            'Rewrite into PHP' => ['RewriteRule ^logo$ images/logo.png [H=application/x-httpd-php,L]', true],
        ];
    }

    #[DataProvider('serverSettings')]
    public function test_server_settings_that_run_code_are_told_apart(string $contents, bool $runsCode): void
    {
        $this->assertSame($runsCode, CoreFiles::runsCode($contents));
    }

    #[RequiresOperatingSystemFamily('Linux')]
    #[RequiresFunction('posix_geteuid')]
    public function test_an_unreadable_folder_does_not_stop_the_file_checks(): void
    {
        if (posix_geteuid() === 0) {
            $this->markTestSkipped('The root account can read every folder.');
        }

        $quarantine = storage_path('app/quarantine/test-'.uniqid());
        $public = storage_path('framework/testing/public-'.uniqid());
        File::ensureDirectoryExists($quarantine.'/inner');
        File::ensureDirectoryExists($public.'/locked/inner');
        file_put_contents($public.'/backup.sql', 'select 1;');
        chmod($quarantine, 0000);
        chmod($public.'/locked', 0000);
        $this->app->usePublicPath($public);

        try {
            $results = collect(app(FileChecks::class)->run());

            $this->assertSame(['files.env', 'files.writable', 'files.open_folders', 'files.public_leftovers'], $results->pluck('id')->all());
            $this->assertNotContains(Status::Skipped, [$results->firstWhere('id', 'files.open_folders')->status, $results->firstWhere('id', 'files.public_leftovers')->status]);
            $leftovers = $results->firstWhere('id', 'files.public_leftovers');
            $this->assertSame(Status::Urgent, $leftovers->status);
            $this->assertStringEndsWith('backup.sql', $leftovers->items[0]['label']);
            $this->assertNull(app(SiteHealth::class)->run('cli')->check('security.files.error'));
        } finally {
            chmod($quarantine, 0755);
            chmod($public.'/locked', 0755);
            File::deleteDirectory($quarantine);
            File::deleteDirectory($public);
        }
    }

    #[RequiresOperatingSystemFamily('Linux')]
    #[RequiresFunction('posix_geteuid')]
    public function test_open_storage_folders_can_be_set_to_755(): void
    {
        $relative = 'storage/framework/testing/open-'.uniqid();
        $folder = base_path($relative);
        File::ensureDirectoryExists($folder);
        chmod($folder, 0777);
        $this->signInAdmin();

        try {
            app(SiteHealth::class)->run();
            $this->assertContains($relative, HealthRun::latestRun()->check('files.open_folders')->fix['params']['paths']);

            $this->post(route('admin.health.fix'), ['check' => 'files.open_folders'])->assertSessionHas('status');
            clearstatcache();

            $this->assertSame(0755, fileperms($folder) & 0777);
        } finally {
            File::deleteDirectory($folder);
        }
    }

    private function settingsCheck(string $id): CheckResult
    {
        return collect(app(SettingsChecks::class)->run())->firstWhere('id', $id);
    }

    private function searchCheck(string $id): CheckResult
    {
        return collect(app(SearchSetupChecks::class)->run())->firstWhere('id', $id);
    }
}
