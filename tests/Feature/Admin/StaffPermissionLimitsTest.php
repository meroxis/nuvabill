<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff managers who are not owners stay inside their own permissions: in the roles they make and
 * change, the roles they give, and the staff accounts whose sign-in they set.
 */
class StaffPermissionLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_managers_only_put_their_own_permissions_in_a_new_role(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage', 'clients.manage'])->create(['name' => 'Raz']));

        $this->post(route('admin.settings.roles.store'), ['name' => 'Everything', 'permissions' => ['clients.manage', 'billing.manage']])
            ->assertSessionHasErrors('permissions');
        $this->post(route('admin.settings.roles.store'), ['name' => 'Settings', 'permissions' => ['settings.manage']])
            ->assertSessionHasErrors('permissions');
        $this->assertFalse(Role::query()->whereIn('name', ['Everything', 'Settings'])->exists());

        // "Create and edit clients" includes "View clients".
        $this->post(route('admin.settings.roles.store'), ['name' => 'Client desk', 'permissions' => ['clients.view', 'staff.manage']])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['clients.view', 'staff.manage'], Role::query()->where('name', 'Client desk')->sole()->permissions);
    }

    public function test_staff_managers_cannot_change_their_own_role(): void
    {
        $manager = $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage'])->create(['name' => 'Raz']));

        $this->get(route('admin.settings.roles.edit', $manager->role))
            ->assertRedirect(route('admin.settings.roles.index'))
            ->assertSessionHas('error', 'You cannot edit your own role. Ask an owner.');
        $this->put(route('admin.settings.roles.update', $manager->role), ['name' => 'Managers', 'permissions' => ['staff.manage']])
            ->assertForbidden();

        $this->assertSame(['staff.manage'], $manager->role->fresh()->permissions);
    }

    public function test_permissions_a_staff_manager_does_not_have_stay_as_they_are_in_other_roles(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage', 'support.manage', 'clients.manage'])->create(['name' => 'Raz']));
        $role = Role::factory()->with(['billing.manage', 'support.manage'])->create(['name' => 'Billing desk']);

        $this->put(route('admin.settings.roles.update', $role), ['name' => 'Billing desk', 'permissions' => ['support.manage', 'settings.manage']])
            ->assertSessionHasErrors('permissions');
        $this->assertEqualsCanonicalizing(['billing.manage', 'support.manage'], $role->fresh()->permissions);

        // Their own permissions can be added and taken away; "billing.manage" is not theirs to remove.
        $this->put(route('admin.settings.roles.update', $role), ['name' => 'Billing and clients', 'permissions' => ['clients.view']])
            ->assertSessionHasNoErrors();

        $role->refresh();
        $this->assertSame('Billing and clients', $role->name);
        $this->assertEqualsCanonicalizing(['billing.manage', 'clients.view'], $role->permissions);
    }

    public function test_the_role_editor_locks_permissions_a_staff_manager_does_not_have(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage'])->create(['name' => 'Raz']));
        $page = $this->get(route('admin.settings.roles.create'))->assertOk()
            ->assertSee('You can only tick permissions that your own role has.');

        $this->assertMatchesRegularExpression('/value="settings\.manage"[^>]*\sdisabled/', $page->getContent());
        $this->assertDoesNotMatchRegularExpression('/value="staff\.manage"[^>]*\sdisabled/', $page->getContent());

        $this->signInAdmin(Admin::factory()->create(['name' => 'Mer']));
        $page = $this->get(route('admin.settings.roles.create'))->assertOk()
            ->assertDontSee('You can only tick permissions that your own role has.');

        $this->assertDoesNotMatchRegularExpression('/name="permissions\[\]"[^>]*\sdisabled/', $page->getContent());
    }

    public function test_staff_managers_only_give_roles_whose_permissions_they_all_have(): void
    {
        $manager = $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage', 'support.manage'])->create(['name' => 'Raz']));
        $bigger = Role::factory()->with(['support.manage', 'billing.manage'])->create(['name' => 'Billing desk']);
        $smaller = Role::factory()->with(['support.manage'])->create(['name' => 'Support desk']);
        $colleague = Admin::factory()->create(['name' => 'Las', 'role_id' => $smaller->id]);

        $this->post(route('admin.settings.staff.store'), ['name' => 'Mer', 'email' => 'mer@example.test', 'role_id' => $bigger->id, 'is_active' => 1, 'password' => 'a-new-password-1'])
            ->assertSessionHasErrors('role_id');
        $this->assertFalse(Admin::query()->where('email', 'mer@example.test')->exists());

        $this->put(route('admin.settings.staff.update', $colleague), $this->form($colleague, ['role_id' => $bigger->id]))
            ->assertSessionHasErrors('role_id');
        $this->assertSame($smaller->id, $colleague->fresh()->role_id);

        $this->post(route('admin.settings.staff.store'), ['name' => 'Mer', 'email' => 'mer@example.test', 'role_id' => $smaller->id, 'is_active' => 1, 'password' => 'a-new-password-1'])
            ->assertSessionHasNoErrors();
        $this->assertSame($smaller->id, Admin::query()->where('email', 'mer@example.test')->sole()->role_id);

        // The form only offers what they can give.
        $this->get(route('admin.settings.staff.create'))->assertOk()->assertSee('Support desk')->assertDontSee('Billing desk');
        $this->assertSame($manager->role_id, $manager->fresh()->role_id);
    }

    public function test_staff_managers_cannot_change_their_own_role_assignment(): void
    {
        $manager = $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage', 'support.manage'])->create(['name' => 'Raz']));
        $other = Role::factory()->with(['support.manage'])->create(['name' => 'Support desk']);

        $this->put(route('admin.settings.staff.update', $manager), $this->form($manager, ['role_id' => $other->id]))
            ->assertSessionHasErrors('role_id');
        $this->assertNotSame($other->id, $manager->fresh()->role_id);

        // Everything else on their own account still saves.
        $this->put(route('admin.settings.staff.update', $manager), $this->form($manager, ['name' => 'Raz R']))
            ->assertSessionHasNoErrors();
        $this->assertSame('Raz R', $manager->fresh()->name);
    }

    public function test_staff_managers_cannot_set_the_sign_in_of_staff_with_more_permissions(): void
    {
        $this->signInAdmin(Admin::factory()->withPermissions(['staff.manage', 'support.manage'])->create(['name' => 'Raz']));
        $billing = Admin::factory()->withPermissions(['support.manage', 'billing.manage'])->create(['name' => 'Las', 'email' => 'las@example.test']);
        $support = Admin::factory()->withPermissions(['support.manage'])->create(['name' => 'Mer', 'email' => 'mer@example.test']);

        $this->put(route('admin.settings.staff.update', $billing), $this->form($billing, ['password' => 'a-new-password-1']))
            ->assertSessionHasErrors('password');
        $this->put(route('admin.settings.staff.update', $billing), $this->form($billing, ['email' => 'raz.other@example.test']))
            ->assertSessionHasErrors('email');

        $billing->refresh();
        $this->assertSame('las@example.test', $billing->email);
        $this->assertTrue(Hash::check('password', $billing->password));

        // The name, and whether they can sign in, can still be changed by the staff manager.
        $this->put(route('admin.settings.staff.update', $billing), $this->form($billing, ['name' => 'Las B', 'is_active' => 0]))
            ->assertSessionHasNoErrors();
        $this->assertSame('Las B', $billing->fresh()->name);
        $this->assertFalse($billing->fresh()->is_active);

        // Staff with the same or fewer permissions can get a new password from them.
        $this->put(route('admin.settings.staff.update', $support), $this->form($support, ['password' => 'a-new-password-1']))
            ->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('a-new-password-1', $support->fresh()->password));
    }

    public function test_owners_give_any_permission_and_any_role(): void
    {
        $owner = $this->signInAdmin(Admin::factory()->create(['name' => 'Mer']));
        $staff = Admin::factory()->withPermissions(['support.manage'])->create(['name' => 'Las']);

        $this->post(route('admin.settings.roles.store'), ['name' => 'Billing desk', 'permissions' => ['billing.manage', 'settings.manage']])
            ->assertSessionHasNoErrors();
        $role = Role::query()->where('name', 'Billing desk')->sole();

        $this->put(route('admin.settings.roles.update', $role), ['name' => 'Billing desk', 'permissions' => ['billing.manage']])
            ->assertSessionHasNoErrors();
        $this->assertSame(['billing.manage'], $role->fresh()->permissions);

        $this->put(route('admin.settings.staff.update', $staff), $this->form($staff, ['role_id' => $role->id, 'password' => 'a-new-password-1']))
            ->assertSessionHasNoErrors();
        $this->assertSame($role->id, $staff->fresh()->role_id);
        $this->assertTrue($owner->fresh()->role->isOwner());
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
