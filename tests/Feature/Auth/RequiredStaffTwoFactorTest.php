<?php

namespace Tests\Feature\Auth;

use App\Models\Admin;
use App\Models\ApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * When two-factor login is required for staff, staff who have not turned it on can only set it up:
 * they can neither make an API key nor use one they made before.
 */
class RequiredStaffTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_without_two_factor_cannot_make_an_api_key(): void
    {
        $this->setSettings(['security.staff_two_factor' => 'required']);
        $admin = Admin::factory()->create();

        $this->actingAs($admin, 'admin')->get(route('admin.dashboard'))->assertRedirect(route('admin.profile.edit'));
        $this->get(route('admin.profile.edit'))->assertOk();

        $this->post(route('admin.profile.api-keys.store'), ['name' => 'Reports', 'can_write' => '1'])
            ->assertRedirect(route('admin.profile.edit'))
            ->assertSessionMissing('new_api_key');
        $this->assertSame(0, ApiToken::query()->count());
    }

    public function test_an_older_api_key_stops_working_until_two_factor_is_on(): void
    {
        $admin = Admin::factory()->create();
        [, $plain] = ApiToken::issue($admin, 'Reports', false);
        $this->setSettings(['security.staff_two_factor' => 'required']);

        $this->getJson('/api/v1/clients', ['Authorization' => 'Bearer '.$plain])->assertForbidden();

        $admin->forceFill(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()])->save();
        Auth::forgetGuards();

        $this->getJson('/api/v1/clients', ['Authorization' => 'Bearer '.$plain])->assertOk();
    }

    public function test_staff_with_two_factor_make_and_use_api_keys_as_before(): void
    {
        $this->setSettings(['security.staff_two_factor' => 'required']);
        $admin = Admin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);

        $this->actingAs($admin, 'admin')->post(route('admin.profile.api-keys.store'), ['name' => 'Reports', 'can_write' => '0'])
            ->assertSessionHas('new_api_key');
        $plain = session('new_api_key');
        Auth::forgetGuards();

        $this->getJson('/api/v1/clients', ['Authorization' => 'Bearer '.$plain])->assertOk();
    }
}
