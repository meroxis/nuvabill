<?php

namespace Tests\Feature\Admin;

use App\Health\Checks\ExtensionChecks;
use App\Health\CoreFiles;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Models\HealthRun;
use App\Models\MarketplaceInstall;
use App\Support\Settings;
use App\Support\Themes;
use App\Updates\Signature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * Site health's view of Nuvabill's own files: changed and planted code, and "Mark as mine".
 */
class SiteHealthFilesTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
        $this->root = storage_path('framework/testing/core-'.uniqid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_mark_as_mine_refuses_a_file_changed_after_the_check(): void
    {
        $files = $this->signedRoot(['app/Changed.php' => '<?php // original', 'public/index.php' => '<?php // front door']);
        file_put_contents($this->root.'/app/Changed.php', '<?php // my own edit');
        $this->signInAdmin();
        app(SiteHealth::class)->run();

        $fix = HealthRun::latestRun()->check('core.files')->fix;
        $this->assertSame('core.accept', $fix['action']);
        $this->assertSame(['app/Changed.php' => hash('sha256', '<?php // my own edit')], $fix['params']['hashes']);

        // Someone adds code after the check staff are looking at.
        file_put_contents($this->root.'/app/Changed.php', '<?php // my own edit'."\n".'eval($_POST[1]);');

        $this->post(route('admin.health.fix'), ['check' => 'core.files'])->assertSessionHas('error');

        $this->assertArrayNotHasKey('app/Changed.php', (array) setting('health.accepted_files'));
        $this->assertSame(['app/Changed.php'], array_column($files->compare($files->manifest()['files'])['changed'], 'path'));
    }

    public function test_mark_as_mine_accepts_the_content_that_was_checked(): void
    {
        $files = $this->signedRoot(['app/Changed.php' => '<?php // original']);
        file_put_contents($this->root.'/app/Changed.php', '<?php // my own edit');
        $this->signInAdmin();
        app(SiteHealth::class)->run();

        $this->post(route('admin.health.fix'), ['check' => 'core.files'])->assertSessionHas('status');

        $this->assertSame(hash('sha256', '<?php // my own edit'), setting('health.accepted_files')['app/Changed.php']);
        $this->assertSame([], $files->compare($files->manifest()['files'])['changed']);
        $this->assertSame(Status::Passed, HealthRun::latestRun()->check('core.files')->status);
    }

    public function test_a_result_saved_without_hashes_accepts_nothing(): void
    {
        $files = $this->signedRoot(['app/Changed.php' => '<?php // original']);
        file_put_contents($this->root.'/app/Changed.php', '<?php // edited');

        $this->expectException(RuntimeException::class);

        try {
            $files->accept(['app/Changed.php'], []);
        } finally {
            $this->assertArrayNotHasKey('app/Changed.php', (array) setting('health.accepted_files'));
        }
    }

    public function test_hundreds_of_planted_files_are_listed_and_accepted_a_hundred_at_a_time(): void
    {
        $this->signedRoot(['public/index.php' => '<?php // front door']);
        File::ensureDirectoryExists($this->root.'/public/vendor/editor');

        foreach (range(1, 150) as $number) {
            file_put_contents($this->root."/public/vendor/editor/plugin-{$number}.js", "console.log({$number});");
        }

        $run = app(SiteHealth::class)->run();
        $check = $run->check('core.planted');

        $this->assertSame(150, $check->params['count']);
        $this->assertCount(100, $check->items);
        $this->assertCount(100, $check->fix['params']['paths']);
        $this->assertCount(100, $check->fix['params']['hashes']);
    }

    public function test_a_database_error_during_a_fix_shows_a_plain_message(): void
    {
        $this->signedRoot(['app/Changed.php' => '<?php // original']);
        file_put_contents($this->root.'/app/Changed.php', '<?php // edited');
        $this->signInAdmin();
        app(SiteHealth::class)->run();

        $this->partialMock(CoreFiles::class, fn (MockInterface $mock) => $mock->shouldReceive('accept')->andThrow(
            new QueryException('sqlite', 'update "settings" set "value" = ?', ['{"app\/Changed.php":"secret-hash"}'], new \PDOException('Data too long for column'))
        ));

        $this->post(route('admin.health.fix'), ['check' => 'core.files'])
            ->assertSessionHas('error', 'The fix could not be saved in the database. The details are in the log.');
    }

    public function test_server_settings_and_config_files_that_run_code_are_found(): void
    {
        $files = $this->signedRoot([
            'public/index.php' => '<?php // front door',
            'config/app.php' => '<?php return [];',
        ]);
        File::ensureDirectoryExists($this->root.'/public/images/storage');
        File::ensureDirectoryExists($this->root.'/public/images/icons');
        File::ensureDirectoryExists($this->root.'/public/hot');
        File::ensureDirectoryExists($this->root.'/public/storage');

        // Shipped and hosting panel settings that run nothing extra are fine.
        File::copy(base_path('public/.htaccess'), $this->root.'/public/.htaccess');
        File::copy(base_path('.htaccess'), $this->root.'/.htaccess');
        file_put_contents($this->root.'/public/images/icons/.htaccess', "<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php84 .php .php8 .phtml\n</IfModule>\n");

        // Ways to keep a backdoor running.
        file_put_contents($this->root.'/public/.user.ini', 'auto_prepend_file='.$this->root.'/public/images/logo.png');
        file_put_contents($this->root.'/public/images/.htaccess', "AddType application/x-httpd-php .png\n");
        file_put_contents($this->root.'/config/zz.php', '<?php return [];');
        file_put_contents($this->root.'/public/images/storage/s.php', '<?php // hidden');
        file_put_contents($this->root.'/public/hot/t.php', '<?php // hidden');
        file_put_contents($this->root.'/public/s..php', '<?php // hidden');

        // The real storage link and Vite's hot file are still left out.
        file_put_contents($this->root.'/public/storage/upload.php', '<?php // a client upload');

        $planted = array_column($files->compare($files->manifest()['files'])['planted'], 'path');

        $this->assertEqualsCanonicalizing([
            'config/zz.php',
            'public/.user.ini',
            'public/hot/t.php',
            'public/images/.htaccess',
            'public/images/storage/s.php',
            'public/s..php',
        ], $planted);

        // A name with ".." in it can be moved to quarantine; a ".." folder step never.
        $this->assertSame(1, $files->quarantine(['public/s..php']));
        $this->assertFileDoesNotExist($this->root.'/public/s..php');
        $this->assertFalse($files->isSafePath('public/../.env'));
        $this->assertFalse($files->isSafePath('public/..\\.env'));
        $this->assertFalse($files->isSafePath('storage\\app\\x.php'));

        $this->expectException(RuntimeException::class);
        $files->quarantine(['public/../.env']);
    }

    public function test_server_settings_files_can_be_marked_as_mine_but_not_moved_away(): void
    {
        $this->signedRoot(['public/index.php' => '<?php // front door']);
        file_put_contents($this->root.'/public/.user.ini', "auto_prepend_file=/home/someone/waf.php\n");
        $this->signInAdmin();
        app(SiteHealth::class)->run();

        $check = HealthRun::latestRun()->check('core.planted');
        $this->assertSame(Status::Urgent, $check->status);
        $this->assertArrayNotHasKey('fix', $check->items[0]);

        $this->post(route('admin.health.fix'), ['check' => 'core.planted'])->assertSessionHas('status');

        $this->assertSame(Status::Passed, HealthRun::latestRun()->check('core.planted')->status);
    }

    public function test_a_hand_copied_package_named_like_an_installed_package_of_another_type_is_reported(): void
    {
        $this->signedRoot(['public/index.php' => '<?php // front door']);
        MarketplaceInstall::query()->create(['slug' => 'aurora', 'type' => 'theme', 'name' => 'Aurora', 'version' => '1.0.0']);
        MarketplaceInstall::query()->create(['slug' => 'discord', 'type' => 'addon', 'name' => 'Discord', 'version' => '1.0.0']);
        File::ensureDirectoryExists($this->root.'/themes/aurora');
        File::ensureDirectoryExists($this->root.'/extensions/addons/discord');
        File::ensureDirectoryExists($this->root.'/extensions/addons/aurora');
        file_put_contents($this->root.'/extensions/addons/aurora/extension.json', '{"slug":"aurora","type":"addon"}');

        $check = collect(app(ExtensionChecks::class)->run())->firstWhere('id', 'extensions.origin');

        $this->assertSame(Status::Warning, $check->status);
        $this->assertSame(['extensions/addons/aurora/'], array_column($check->items, 'label'));
    }

    public function test_a_hand_copied_theme_named_like_an_installed_add_on_can_still_be_quarantined(): void
    {
        $themes = $this->root.'/themes';
        File::copyDirectory(base_path('themes/nova'), $themes.'/nova');
        File::ensureDirectoryExists($themes.'/discord/views');
        File::put($themes.'/discord/theme.json', json_encode(['slug' => 'discord', 'name' => 'Discord look', 'version' => '1.0.0']));
        config(['nuvabill.themes_path' => $themes]);
        $this->app->forgetInstance(Themes::class);
        MarketplaceInstall::query()->create(['slug' => 'discord', 'type' => 'addon', 'name' => 'Discord', 'version' => '1.0.0']);

        $check = collect(app(ExtensionChecks::class)->run())->firstWhere('id', 'extensions.unused_themes');

        $this->assertSame(['discord'], array_column($check->items, 'label'));
        $this->assertSame('themes.quarantine', $check->items[0]['fix']['action']);
    }

    /**
     * A copy of Nuvabill in a temporary folder with a signed list of the given files.
     *
     * @param  array<string, string>  $files  Path => contents.
     */
    private function signedRoot(array $files): CoreFiles
    {
        foreach ($files as $path => $contents) {
            File::ensureDirectoryExists(dirname($this->root.'/'.$path));
            file_put_contents($this->root.'/'.$path, $contents);
        }

        $keys = Signature::generateKeyPair();
        config(['nuvabill.updates.public_key' => $keys['public']]);
        $version = (string) config('nuvabill.version');
        $list = (string) json_encode(['version' => $version, 'files' => array_map(fn (string $contents): string => hash('sha256', $contents), $files)], JSON_UNESCAPED_SLASHES);
        file_put_contents($this->root.'/'.CoreFiles::LIST_FILE, $list);
        file_put_contents($this->root.'/'.CoreFiles::SIGNATURE_FILE, Signature::signFileList($version, $list, $keys['secret']));

        $coreFiles = new CoreFiles(app(Settings::class), $this->root);
        $this->app->instance(CoreFiles::class, $coreFiles);
        $this->assertSame(CoreFiles::STATE_OK, $coreFiles->manifest()['state']);

        return $coreFiles;
    }
}
