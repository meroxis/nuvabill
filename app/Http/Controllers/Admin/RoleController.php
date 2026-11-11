<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RoleController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.roles.index', [
            'roles' => Role::query()->withCount('admins')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.settings.roles.form', ['role' => new Role(['permissions' => []])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $role = Role::create($this->validated($request));
        Activity::log('role.created', "Role {$role->name} created");

        return redirect()->route('admin.settings.roles.index')->with('status', __('Role created.'));
    }

    public function edit(Request $request, Role $role): View|RedirectResponse
    {
        if ($role->isOwner()) {
            return redirect()->route('admin.settings.roles.index')->with('error', __('The Owner role always has every permission and cannot be edited.'));
        }

        if ($this->isOwnRole($request, $role)) {
            return redirect()->route('admin.settings.roles.index')->with('error', __('You cannot edit your own role. Ask an owner.'));
        }

        return view('admin.settings.roles.form', ['role' => $role]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->isOwner() || $this->isOwnRole($request, $role), 403);

        $role->update($this->validated($request, $role));
        Activity::log('role.updated', "Role {$role->name} updated");

        return redirect()->route('admin.settings.roles.index')->with('status', __('Role saved.'));
    }

    /**
     * Staff managers who are not owners cannot change the role they have themselves.
     */
    private function isOwnRole(Request $request, Role $role): bool
    {
        $staff = $request->user('admin');

        return $staff->role?->isOwner() !== true && (int) $staff->role_id === (int) $role->getKey();
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function validated(Request $request, ?Role $role = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(Role::allPermissionKeys())],
        ]);

        $permissions = array_values(array_unique($data['permissions'] ?? []));
        $staff = $request->user('admin');

        // Staff managers who are not owners only give out what their own role has. What the role
        // already has beyond that stays as it is: they can neither add it nor take it away.
        if ($staff->role?->isOwner() !== true) {
            $beyond = fn (mixed $permission): bool => ! $staff->hasPermission((string) $permission);
            $kept = array_values(array_filter($role?->permissions ?? [], $beyond));

            if (array_diff(array_filter($permissions, $beyond), $kept) !== []) {
                throw ValidationException::withMessages(['permissions' => __('You can only give permissions that your own role has.')]);
            }

            $permissions = array_values(array_unique([...array_filter($permissions, fn (string $permission): bool => ! $beyond($permission)), ...$kept]));
        }

        return ['name' => $data['name'], 'permissions' => $permissions];
    }
}
