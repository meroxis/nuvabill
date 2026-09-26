<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Client;
use App\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
