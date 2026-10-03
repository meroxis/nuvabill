<?php

namespace Tests\Feature\Admin;

use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Security\Totp;
use Carbon\CarbonInterval;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class StaffLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_sign_in(): void
    {
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);

        $this->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNotNull($admin->fresh()->last_login_at);
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        Admin::factory()->create(['email' => 'owner@example.test']);

        $this->from(route('admin.login'))
            ->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'wrong'])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_client_accounts_cannot_sign_in_to_the_admin_area(): void
    {
        Client::factory()->create(['email' => 'client@example.test']);

        $this->post(route('admin.login'), ['email' => 'client@example.test', 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('admin');
    }

    public function test_two_factor_login_needs_the_code_from_the_app(): void
    {
        $secret = Totp::generateSecret();
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();

        $this->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password'])
            ->assertRedirect(route('admin.two-factor.challenge'));
        $this->assertGuest('admin');

        $this->post(route('admin.two-factor.challenge'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest('admin');

        $this->post(route('admin.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);
        $admin->forceFill([
            'two_factor_secret' => Totp::generateSecret(),
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => ['abcde-12345', 'fghij-67890'],
        ])->save();

        $this->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password']);
        $this->post(route('admin.two-factor.challenge'), ['recovery_code' => 'abcde-12345'])->assertRedirect(route('admin.dashboard'));

        $this->assertSame(['fghij-67890'], $admin->fresh()->two_factor_recovery_codes);
    }

    public function test_two_factor_codes_are_limited_per_account_whichever_ip_they_come_from(): void
    {
        $secret = Totp::generateSecret();
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $this->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password'])->assertRedirect(route('admin.two-factor.challenge'));

        foreach (range(1, 4) as $try) {
            // A new IP each time, so the rate limit for each IP never answers first.
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.$try])
                ->post(route('admin.two-factor.challenge'), ['code' => $this->wrongCode($secret)])
                ->assertSessionHasErrors('code');
        }

        // The fifth wrong code ends the half-done sign-in: the password step starts again.
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::5'])
            ->post(route('admin.two-factor.challenge'), ['code' => $this->wrongCode($secret)])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('email');
        $this->assertNull(session('admin.two_factor'));

        // Even a new password sign-in from another address cannot use the right code for now.
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::6'])->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password']);
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::7'])
            ->post(route('admin.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
        $this->assertSame(5, ActivityLog::query()->where('action', 'admin.two_factor_failed')->where('subject_id', $admin->id)->count());
        $this->assertTrue(ActivityLog::query()->where('action', 'admin.two_factor_locked')->exists());

        // After the wait, the right code works again.
        $this->travel(16)->minutes();
        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::8'])->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password']);
        $this->post(route('admin.two-factor.challenge'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_passwords_are_limited_per_account_whichever_ip_they_come_from(): void
    {
        Admin::factory()->create(['email' => 'owner@example.test']);

        foreach (range(1, 10) as $try) {
            $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::'.$try])
                ->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'wrong-password-'.$try])
                ->assertSessionHasErrors('email');
        }

        $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::99'])
            ->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password'])
            ->assertSessionHasErrors(['email' => 'Too many tries. Wait 15 minutes, then try again.']);
        $this->assertGuest('admin');
    }

    public function test_a_used_two_factor_code_cannot_be_used_again_with_spaces_inside(): void
    {
        $secret = Totp::generateSecret();
        $admin = Admin::factory()->create(['email' => 'owner@example.test']);
        $admin->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $code = Totp::codeAt($secret, intdiv(time(), 30));

        $this->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password']);
        $this->post(route('admin.two-factor.challenge'), ['code' => $code])->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.logout'));

        // Someone who saw the code types it again with a space or a tab inside.
        foreach ([substr($code, 0, 3).' '.substr($code, 3), substr($code, 0, 1)."\t".substr($code, 1)] as $try => $spaced) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$try])->post(route('admin.login'), ['email' => 'owner@example.test', 'password' => 'password']);
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.'.$try])->post(route('admin.two-factor.challenge'), ['code' => $spaced])->assertSessionHasErrors('code');
            $this->assertGuest('admin');
        }
    }

    public function test_an_unknown_staff_email_still_runs_a_password_check(): void
    {
        // Without it, an unknown email answers faster than a real one, which gives away staff emails.
        Hash::spy();

        $this->post(route('admin.login'), ['email' => 'nobody@example.test', 'password' => 'any-password'])->assertSessionHasErrors('email');

        Hash::shouldHaveReceived('check')->once();
        $this->assertGuest('admin');
    }

    public function test_forgot_password_answers_take_the_same_time_and_the_email_goes_after_the_answer(): void
    {
        Sleep::fake();
        Notification::fake();

        $this->post(route('admin.password.email'), ['email' => 'nobody@example.test'])
            ->assertSessionHas('status', 'If that email belongs to a staff account, a reset link is on its way.');
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMicroseconds >= 750_000, 1);

        $admin = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer.las@example.test']);
        $this->post(route('admin.password.email'), ['email' => 'mer.las@example.test'])
            ->assertSessionHas('status', 'If that email belongs to a staff account, a reset link is on its way.');

        Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalMicroseconds >= 750_000, 2);
        Notification::assertSentToTimes($admin, ResetPassword::class, 1);
    }

    public function test_staff_can_turn_on_two_factor_login(): void
    {
        $admin = $this->signInAdmin();

        $this->post(route('admin.profile.two-factor.start'))->assertRedirect();
        $secret = $admin->fresh()->two_factor_secret;
        $this->assertNotNull($secret);
        $this->get(route('admin.profile.edit'))->assertOk()->assertSee('<svg', false);

        $this->post(route('admin.profile.two-factor.confirm'), ['code' => Totp::codeAt($secret, intdiv(time(), 30))])
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
        $this->assertCount(8, $admin->fresh()->two_factor_recovery_codes);
    }

    /**
     * A six-digit code that is not valid around now (the app code of this, the last or the next 30 seconds).
     */
    private function wrongCode(string $secret): string
    {
        $step = intdiv(time(), 30);
        $valid = [Totp::codeAt($secret, $step - 1), Totp::codeAt($secret, $step), Totp::codeAt($secret, $step + 1)];

        foreach (['111111', '222222', '333333', '444444'] as $code) {
            if (! in_array($code, $valid, true)) {
                return $code;
            }
        }

        return '555555';
    }
}
