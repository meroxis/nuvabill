<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * At least one active owner always stays, and staff managers who are not owners cannot make
 * owners or change an owner's account.
 */
class StaffOwnerGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_last_active_owner_cannot_be_turned_off(): void
    {
        $owner = Admin::factory()->create(['name' => 'Mer Las']);
        $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage'])->create());

        // Turning off "Can sign in" while the Owner role stays would leave no owner who can sign in.
        $this->put(route('admin.settings.staff.update', $owner), $this->form($owner, ['is_active' => 0]))
            ->assertSessionHasErrors('is_active');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_an_owner_can_be_turned_off_when_another_active_owner_stays(): void
    {
        $owner = Admin::factory()->create(['name' => 'Mer Las']);
        $this->signInAdmin(Admin::factory()->create(['name' => 'Raz', 'role_id' => $owner->role_id]));

        $this->put(route('admin.settings.staff.update', $owner), $this->form($owner, ['is_active' => 0]))
            ->assertSessionHasNoErrors();

        $this->assertFalse($owner->fresh()->is_active);
    }

    public function test_staff_managers_who_are_not_owners_cannot_change_an_owner_account(): void
    {
        $owner = Admin::factory()->create(['name' => 'Mer Las', 'email' => 'mer.las@example.test']);
        Admin::factory()->create(['role_id' => $owner->role_id]);
        $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage'])->create());

        $this->put(route('admin.settings.staff.update', $owner), $this->form($owner, ['email' => 'raz@example.test', 'password' => 'a-new-password-1']))
            ->assertSessionHasErrors('role_id');

        $owner->refresh();
        $this->assertSame('mer.las@example.test', $owner->email);
        $this->assertTrue(Hash::check('password', $owner->password));
    }

    public function test_staff_managers_who_are_not_owners_cannot_give_the_owner_role(): void
    {
        $ownerRole = Role::factory()->owner()->create();
        $manager = $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage'])->create());

        $this->post(route('admin.settings.staff.store'), [
            'name' => 'Raz', 'email' => 'raz@example.test', 'role_id' => $ownerRole->id, 'is_active' => 1, 'password' => 'a-new-password-1',
        ])->assertSessionHasErrors('role_id');
        $this->assertFalse(Admin::query()->where('email', 'raz@example.test')->exists());

        $this->put(route('admin.settings.staff.update', $manager), $this->form($manager, ['role_id' => $ownerRole->id]))
            ->assertSessionHasErrors('role_id');
        $this->assertFalse($manager->fresh()->role->isOwner());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(Admin $admin, array $overrides = []): array
    {
        return $overrides + [
            'name' => $admin->name,
            'email' => $admin->email,
            'role_id' => $admin->role_id,
            'is_active' => 1,
            'password' => '',
        ];
    }
}
