<?php

namespace Tests\Feature\Admin;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Health\Checks\StaffChecks;
use App\Health\SiteHealth;
use App\Health\Status;
use App\Jobs\SendChatMessage;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\ApiToken;
use App\Models\HealthRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Site health's staff checks and the fixes behind them.
 */
class SiteHealthStaffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'http://localhost']);
    }

    public function test_a_passkey_alone_does_not_count_as_two_factor(): void
    {
        $owner = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer@example-host.com']);
        $owner->passkeys()->create([
            'name' => 'Phone',
            'credential_id' => 'credential-of-mer-las',
            'credential_hash' => hash('sha256', 'credential-of-mer-las'),
            'public_key' => 'public key',
            'algorithm' => -7,
            'sign_count' => 0,
        ]);

        $check = app(SiteHealth::class)->run()->check('staff.powerful_two_factor');

        $this->assertSame(Status::Urgent, $check->status);
        $this->assertContains('Mer Las', array_column($check->items, 'label'));
        $this->assertSame('staff.require_two_factor', $check->fix['action']);
    }

    public function test_api_key_use_counts_as_staff_activity(): void
    {
        $raz = Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz', 'created_at' => now()->subDays(200), 'last_login_at' => null]);
        [$token] = ApiToken::issue($raz, 'Accounting', false);
        $token->forceFill(['last_used_at' => now()])->save();

        $this->assertSame(Status::Passed, $this->staffCheck('staff.inactive')->status);

        $token->forceFill(['last_used_at' => now()->subDays(100)])->save();
        $this->assertSame(Status::Warning, $this->staffCheck('staff.inactive')->status);
    }

    public function test_keep_me_signed_in_counts_as_a_sign_in(): void
    {
        $raz = Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz', 'created_at' => now()->subDays(200)]);
        $raz->setRememberToken(Str::random(60));
        $raz->forceFill(['last_login_at' => now()->subDays(120)])->save();

        // A new visit after the session ended, with only the "Keep me signed in" cookie.
        $this->withCookie(Auth::guard('admin')->getRecallerName(), $raz->id.'|'.$raz->getRememberToken().'|'.$raz->getAuthPassword())
            ->get(route('admin.dashboard'));

        $this->assertTrue($raz->fresh()->last_login_at->gt(now()->subMinute()));
        $this->assertSame(Status::Passed, $this->staffCheck('staff.inactive')->status);
    }

    public function test_captcha_check_needs_checked_keys(): void
    {
        $this->setSettings([
            'security.captcha_provider' => 'turnstile',
            'security.captcha_site_key' => 'site-key',
            'security.captcha_secret' => 'secret',
            'security.captcha_checked_key' => null,
        ]);

        $check = $this->staffCheck('clients.captcha');
        $this->assertSame(Status::Warning, $check->status);
        $this->assertSame('A CAPTCHA is chosen, but no form asks for it yet', $check->summary);

        $this->setSettings(['security.captcha_checked_key' => 'site-key']);
        $this->assertSame(Status::Passed, $this->staffCheck('clients.captcha')->status);
    }

    public function test_switching_off_idle_staff_skips_people_who_signed_in_since_the_check(): void
    {
        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $raz = Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz', 'last_login_at' => now()->subDays(120)]);
        app(SiteHealth::class)->run();

        $raz->forceFill(['last_login_at' => now()])->save();
        $this->post(route('admin.health.fix'), ['check' => 'staff.inactive'])->assertSessionHas('status');

        $this->assertTrue($raz->fresh()->is_active);
    }

    public function test_security_only_staff_cannot_switch_off_owners_or_staff_managers(): void
    {
        $owner = Admin::factory()->create(['name' => 'Mer Las', 'last_login_at' => now()->subDays(120)]);
        $manager = Admin::factory()->withPermissions(['staff.manage'])->create(['name' => 'Raz', 'last_login_at' => now()->subDays(120)]);
        $idle = Admin::factory()->withPermissions(['clients.view'])->create(['name' => 'Raz', 'last_login_at' => now()->subDays(120)]);
        $this->signInAdmin(Admin::factory()->withPermissions(['security.manage'])->create(['name' => 'Raz', 'last_login_at' => now()]));
        app(SiteHealth::class)->run();

        $this->assertEqualsCanonicalizing([$owner->id, $manager->id, $idle->id], HealthRun::latestRun()->check('staff.inactive')->fix['params']['ids']);

        $this->post(route('admin.health.fix'), ['check' => 'staff.inactive'])->assertSessionHas('status');

        $this->assertTrue($owner->fresh()->is_active);
        $this->assertTrue($manager->fresh()->is_active);
        $this->assertFalse($idle->fresh()->is_active);
    }

    public function test_deleting_unused_tokens_keeps_tokens_used_since_the_check(): void
    {
        $owner = $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        [$used] = ApiToken::issue($owner, 'Billing sync', true);
        [$unused] = ApiToken::issue($owner, 'Old script', true);
        $used->forceFill(['last_used_at' => now()->subDays(120)])->save();
        $unused->forceFill(['last_used_at' => now()->subDays(120)])->save();
        app(SiteHealth::class)->run();

        $used->forceFill(['last_used_at' => now()])->save();
        $this->post(route('admin.health.fix'), ['check' => 'api.write_tokens'])->assertSessionHas('status');

        $this->assertNotNull($used->fresh());
        $this->assertNull($unused->fresh());
    }

    public function test_security_alert_posts_to_the_staff_chat_once(): void
    {
        Mail::fake();
        Queue::fake();
        $this->setSettings(['chat.telegram_token' => '123:token', 'chat.telegram_bot' => 'site_bot', 'chat.telegram_staff_chat' => '-100123']);
        Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer@example-host.com']);
        Admin::factory()->create(['name' => 'Raz', 'email' => 'raz@example-host.com']);
        Admin::factory()->create(['name' => 'Raz', 'email' => 'raz.billing@example-host.com']);

        app(SiteHealth::class)->run();

        Mail::assertSent(TemplatedMessage::class, 3);
        Queue::assertPushed(SendChatMessage::class, 1);
        Queue::assertPushed(SendChatMessage::class, fn (SendChatMessage $job): bool => $job->event === 'staff' && $job->context['chat'] === '-100123');
    }

    public function test_add_on_check_groups_added_with_extend_are_run(): void
    {
        app(SiteHealth::class)->extend(new class extends CheckGroup
        {
            public function key(): string
            {
                return 'addon';
            }

            public function section(): string
            {
                return self::SECURITY;
            }

            public function title(): string
            {
                return 'Add-on checks';
            }

            public function description(): string
            {
                return 'Checks from an add-on.';
            }

            public function run(): array
            {
                return [$this->check('security.addon.example', 'An add-on check')->urgent('Something to fix')];
            }
        });

        $this->artisan('nuvabill:security-check')->assertFailed();

        $this->assertSame(Status::Urgent, HealthRun::latestRun()->check('security.addon.example')?->status);

        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer Las']));
        $this->get(route('admin.health.group', ['security', 'addon']))->assertOk()->assertSee('An add-on check');
    }

    private function staffCheck(string $id): CheckResult
    {
        return collect(app(StaffChecks::class)->run())->firstWhere('id', $id);
    }
}
