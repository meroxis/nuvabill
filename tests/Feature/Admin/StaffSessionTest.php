<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Role;
use App\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * A new staff password must end every other signed-in session (for example a stolen one), while
 * the person who changed it stays signed in.
 */
class StaffSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_password_reset_signs_out_existing_staff_sessions(): void
    {
        $admin = Admin::factory()->create(['email' => 'mer.las@example.test']);
        $stolen = $this->signedInSession('mer.las@example.test');

        // The staff member resets the password from another device.
        $this->switchTo([]);
        $token = Password::broker('admins')->createToken($admin);
        $this->post(route('admin.password.update'), ['token' => $token, 'email' => 'mer.las@example.test', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
            ->assertRedirect(route('admin.login'));

        $this->switchTo($stolen)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_changing_the_own_password_keeps_this_session_and_ends_the_others(): void
    {
        Admin::factory()->create(['email' => 'mer.las@example.test']);
        $other = $this->signedInSession('mer.las@example.test');

        $this->switchTo([]);
        $this->post(route('admin.login'), ['email' => 'mer.las@example.test', 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->put(route('admin.profile.password'), ['current_password' => 'password', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
            ->assertSessionHas('status');

        // This device: the guard reloads the staff member from the session, as on the next request.
        Auth::forgetGuards();
        $this->get(route('admin.dashboard'))->assertOk();

        $this->switchTo($other)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_a_password_set_in_staff_settings_signs_out_that_staff_member(): void
    {
        $owner = Admin::factory()->create();
        $staff = Admin::factory()->create(['email' => 'raz@example.test', 'role_id' => Role::factory()->with(['clients.view'])]);
        $session = $this->signedInSession('raz@example.test');

        $this->switchTo([]);
        $this->actingAs($owner, 'admin')
            ->put(route('admin.settings.staff.update', $staff), [
                'name' => $staff->name, 'email' => $staff->email, 'role_id' => $staff->role_id, 'is_active' => 1, 'password' => 'a-new-password-1',
            ])
            ->assertSessionHasNoErrors();

        $this->switchTo($session)->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->assertGuest('admin');
    }

    public function test_owners_editing_their_own_account_stay_signed_in(): void
    {
        Admin::factory()->create();
        $owner = Admin::factory()->create(['email' => 'mer.las@example.test']);
        $this->post(route('admin.login'), ['email' => 'mer.las@example.test', 'password' => 'password'])->assertRedirect(route('admin.dashboard'));

        $this->put(route('admin.settings.staff.update', $owner), [
            'name' => 'Mer Las', 'email' => 'mer.las@example.test', 'role_id' => $owner->role_id, 'is_active' => 1, 'password' => 'a-new-password-1',
        ])->assertSessionHasNoErrors();

        Auth::forgetGuards();
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_password_checks_on_the_profile_are_rate_limited(): void
    {
        $admin = $this->signInAdmin(Admin::factory()->create(['password' => 'old-password-123']));

        foreach (range(1, 6) as $try) {
            $this->put(route('admin.profile.password'), ['current_password' => 'wrong-password-'.$try, 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
                ->assertSessionHasErrors('current_password');
        }

        $this->put(route('admin.profile.password'), ['current_password' => 'old-password-123', 'password' => 'a-new-password-1', 'password_confirmation' => 'a-new-password-1'])
            ->assertStatus(429);
        $this->assertTrue(Hash::check('old-password-123', $admin->fresh()->password));
    }

    public function test_turning_off_two_factor_login_is_rate_limited(): void
    {
        $admin = Admin::factory()->create(['password' => 'old-password-123']);
        $admin->forceFill(['two_factor_secret' => Totp::generateSecret(), 'two_factor_confirmed_at' => now()])->save();
        $this->signInAdmin($admin);

        foreach (range(1, 6) as $try) {
            $this->delete(route('admin.profile.two-factor.disable'), ['current_password' => 'wrong-password-'.$try])->assertSessionHasErrors('current_password');
        }

        $this->delete(route('admin.profile.two-factor.disable'), ['current_password' => 'old-password-123'])->assertStatus(429);
        $this->assertTrue($admin->fresh()->hasTwoFactorEnabled());
    }

    /**
     * Sign in with the password as another browser would, and keep that browser's session.
     *
     * @return array<string, mixed>
     */
    private function signedInSession(string $email): array
    {
        $this->post(route('admin.login'), ['email' => $email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $session = session()->all();

        $this->switchTo([]);

        return $session;
    }

    /**
     * Continue as another browser: only its session, nobody signed in until the session says so.
     *
     * @param  array<string, mixed>  $session
     */
    private function switchTo(array $session): static
    {
        $this->flushSession();
        Auth::forgetGuards();

        return $this->withSession($session);
    }
}
