<?php

namespace Tests\Feature\Admin;

use App\Health\CoreFiles;
use App\Health\DatabaseInspector;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Mail\TemplatedMessage;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\HealthRun;
use App\Models\Invoice;
use App\Support\Settings;
use App\Support\Themes;
use App\Updates\Signature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SiteHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Local addresses are never opened from outside; tests that need it set their own.
        config(['app.url' => 'http://localhost']);
    }

    public function test_staff_with_the_security_right_see_site_health_and_others_do_not(): void
    {
        $this->signInAdmin();
        app(SiteHealth::class)->run();

        $this->get(route('admin.health.index'))->assertOk()->assertSee('Staff and access')->assertSee('Fix these first');
        $this->get(route('admin.health.database'))->assertOk()->assertSee('Clean up old records');
        $this->get(route('admin.health.group', ['security', 'staff']))->assertOk();
        $this->get(route('admin.health.checks', 'security'))->assertOk();

        $this->signInAdmin(Admin::factory()->withPermissions(['clients.view'])->create());
        $this->get(route('admin.health.index'))->assertForbidden();
    }

    public function test_a_check_scores_the_site_and_emails_new_urgent_issues_once(): void
    {
        Mail::fake();
        Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer@example-host.com']);

        $first = app(SiteHealth::class)->run();

        $this->assertSame(Status::Urgent, $first->check('staff.powerful_two_factor')->status);
        $this->assertGreaterThan(0, $first->urgent_count);
        $this->assertNotNull($first->security_score);
        $this->assertLessThan(100, $first->security_score);
        Mail::assertSent(TemplatedMessage::class, fn (TemplatedMessage $mail): bool => $mail->hasTo('mer@example-host.com') && str_contains($mail->subjectLine, 'security issue'));

        Mail::fake();
        app(SiteHealth::class)->run();
        Mail::assertNothingSent();
    }

    public function test_fix_buttons_repair_only_what_the_saved_result_names(): void
    {
        $owner = $this->signInAdmin();
        $idle = Admin::factory()->withPermissions(['clients.view'])->create(['last_login_at' => now()->subDays(120)]);
        $active = Admin::factory()->withPermissions(['clients.view'])->create(['last_login_at' => now()]);
        app(SiteHealth::class)->run();

        // Extra details from the browser are ignored: the IDs come from the saved check.
        $this->post(route('admin.health.fix'), ['check' => 'staff.inactive', 'ids' => [$active->id, $owner->id]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertFalse($idle->fresh()->is_active);
        $this->assertTrue($active->fresh()->is_active);
        $this->assertTrue($owner->fresh()->is_active);
        $this->assertSame(Status::Passed, HealthRun::latestRun()->check('staff.inactive')->status);

        $this->post(route('admin.health.fix'), ['check' => 'staff.powerful_two_factor'])->assertSessionHas('status');
        $this->assertSame('required', setting('security.staff_two_factor'));
    }

    public function test_an_unused_theme_added_by_hand_can_be_moved_to_quarantine(): void
    {
        $themes = storage_path('framework/testing/themes-'.uniqid());
        File::copyDirectory(base_path('themes/nova'), $themes.'/nova');
        File::ensureDirectoryExists($themes.'/rapidnet/views');
        File::put($themes.'/rapidnet/theme.json', json_encode(['slug' => 'rapidnet', 'name' => 'RapidNet', 'version' => '1.0.0']));
        config(['nuvabill.themes_path' => $themes]);
        $this->app->forgetInstance(Themes::class);
        $this->signInAdmin();
        app(SiteHealth::class)->run();

        $check = HealthRun::latestRun()->check('extensions.unused_themes');
        $this->assertSame(['rapidnet'], array_column($check->items, 'label'));
        $this->assertSame('themes.quarantine', $check->items[0]['fix']['action']);

        $this->post(route('admin.health.fix'), ['check' => 'extensions.unused_themes', 'item' => 0])->assertSessionHas('status');

        $this->assertDirectoryDoesNotExist($themes.'/rapidnet');
        $this->assertDirectoryExists($themes.'/nova');
        $this->assertNotEmpty(glob(storage_path('app/quarantine/*/themes/rapidnet/theme.json')));
        $this->assertSame(Status::Passed, HealthRun::latestRun()->check('extensions.unused_themes')->status);

        File::deleteDirectory($themes);
        File::deleteDirectory(storage_path('app/quarantine'));
    }

    public function test_ignored_checks_stop_counting_until_staff_count_them_again(): void
    {
        $this->signInAdmin();
        $run = app(SiteHealth::class)->run();
        $urgent = $run->urgent_count;

        $this->post(route('admin.health.ignore'), ['check' => 'staff.powerful_two_factor', 'reason' => 'Only used from the office'])->assertSessionHas('status');

        $run = HealthRun::latestRun();
        $this->assertTrue($run->check('staff.powerful_two_factor')->ignored);
        $this->assertSame($urgent - 1, $run->urgent_count);
        $this->get(route('admin.health.checks', ['section' => 'security', 'show' => 'ignored']))->assertSee('Only used from the office');

        $this->post(route('admin.health.unignore'), ['check' => 'staff.powerful_two_factor']);
        $this->assertSame($urgent, HealthRun::latestRun()->urgent_count);
    }

    public function test_the_site_is_opened_from_outside_like_a_stranger(): void
    {
        config(['app.url' => 'https://billing.example-host.com']);
        Http::fake([
            'billing.example-host.com/.env' => Http::response("APP_NAME=Hosting\nAPP_KEY=base64:secret\n"),
            'billing.example-host.com/' => Http::response('<html></html>', 200, [
                'Content-Security-Policy' => "default-src 'self'; frame-ancestors 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'Strict-Transport-Security' => 'max-age=31536000',
            ]),
            '*' => Http::response('Not found', 404),
        ]);

        $run = app(SiteHealth::class)->run();

        $this->assertSame(Status::Urgent, $run->check('outside.env')->status);
        $this->assertSame(Status::Passed, $run->check('outside.git')->status);
        $this->assertSame(Status::Passed, $run->check('outside.database')->status);
        $this->assertSame(Status::Passed, $run->check('outside.headers')->status);
    }

    public function test_changed_and_planted_code_is_found_and_can_be_accepted_or_quarantined(): void
    {
        $root = storage_path('framework/testing/core-'.uniqid());
        File::ensureDirectoryExists($root.'/app');
        File::ensureDirectoryExists($root.'/public');
        file_put_contents($root.'/app/Kept.php', '<?php // original');
        file_put_contents($root.'/app/Changed.php', '<?php // original');
        file_put_contents($root.'/public/index.php', '<?php // front door');

        $keys = Signature::generateKeyPair();
        config(['nuvabill.updates.public_key' => $keys['public']]);
        $version = (string) config('nuvabill.version');
        $list = (string) json_encode(['version' => $version, 'files' => collect(['app/Kept.php', 'app/Changed.php', 'public/index.php'])
            ->mapWithKeys(fn (string $path): array => [$path => hash_file('sha256', $root.'/'.$path)])->all()], JSON_UNESCAPED_SLASHES);
        file_put_contents($root.'/'.CoreFiles::LIST_FILE, $list);
        file_put_contents($root.'/'.CoreFiles::SIGNATURE_FILE, Signature::signFileList($version, $list, $keys['secret']));

        file_put_contents($root.'/app/Changed.php', '<?php // edited');
        file_put_contents($root.'/public/cache.php', '<?php eval($_POST["x"]);');

        $files = new CoreFiles(app(Settings::class), $root);
        $manifest = $files->manifest();
        $this->assertSame(CoreFiles::STATE_OK, $manifest['state']);

        $result = $files->compare($manifest['files']);
        $this->assertSame(['app/Changed.php'], array_column($result['changed'], 'path'));
        $this->assertSame(['public/cache.php'], array_column($result['planted'], 'path'));
        $this->assertTrue($result['planted'][0]['public']);

        $files->accept(['app/Changed.php']);
        $files->quarantine(['public/cache.php']);
        $result = $files->compare($manifest['files']);

        $this->assertSame([], $result['changed']);
        $this->assertSame([], $result['planted']);
        $this->assertFileDoesNotExist($root.'/public/cache.php');
        $this->assertFalse($files->isSafePath('../.env'));

        // A list someone edited no longer matches its signature.
        file_put_contents($root.'/'.CoreFiles::LIST_FILE, str_replace('app/Kept.php', 'app/Other.php', $list));
        $this->assertSame(CoreFiles::STATE_BAD_SIGNATURE, $files->manifest()['state']);

        File::deleteDirectory($root);
    }

    public function test_database_clean_up_removes_old_logs_but_never_billing_records(): void
    {
        $client = Client::factory()->create(['created_at' => now()->subYears(3)]);
        Invoice::factory()->create(['client_id' => $client->id, 'created_at' => now()->subYears(3)]);
        ActivityLog::query()->create(['action' => 'old', 'description' => 'Old entry'])->forceFill(['created_at' => now()->subDays(400)])->save();
        ActivityLog::query()->create(['action' => 'new', 'description' => 'New entry']);

        $database = app(DatabaseInspector::class);
        $this->assertSame(1, $database->oldRecords()['activity']['count']);

        $removed = $database->cleanUp();

        $this->assertSame(1, $removed['activity']);
        $this->assertSame(['new'], ActivityLog::query()->where('action', '!=', 'database.cleaned')->pluck('action')->all());
        $this->assertSame(1, Client::query()->count());
        $this->assertSame(1, Invoice::query()->count());
    }

    public function test_the_command_line_check_fails_while_an_urgent_issue_is_open(): void
    {
        Admin::factory()->create();

        $this->artisan('nuvabill:security-check', ['--json' => true])
            ->expectsOutputToContain('"urgent"')
            ->assertFailed();
    }

    public function test_failed_staff_sign_ins_are_logged_without_what_was_typed(): void
    {
        $admin = Admin::factory()->create(['email' => 'raz@example-host.com']);

        $this->post(route('admin.login'), ['email' => 'raz@example-host.com', 'password' => 'wrong-password'])->assertSessionHasErrors('email');
        $this->post(route('admin.login'), ['email' => 'someone@example-host.com', 'password' => 'wrong-password']);

        $logs = ActivityLog::query()->where('action', 'admin.login_failed')->get();
        $this->assertCount(2, $logs);
        $this->assertSame($admin->id, $logs->first()->subject_id);
        $this->assertStringNotContainsString('someone@example-host.com', $logs->last()->description);
        $this->assertStringNotContainsString('wrong-password', $logs->implode('description'));
    }
}
