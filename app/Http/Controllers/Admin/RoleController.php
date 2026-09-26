<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function edit(Role $role): View|RedirectResponse
    {
        if ($role->isOwner()) {
            return redirect()->route('admin.settings.roles.index')->with('error', __('The Owner role always has every permission and cannot be edited.'));
        }

        return view('admin.settings.roles.form', ['role' => $role]);
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if($role->isOwner(), 403);

        $role->update($this->validated($request));
        Activity::log('role.updated', "Role {$role->name} updated");

        return redirect()->route('admin.settings.roles.index')->with('status', __('Role saved.'));
    }

    /**
     * @return array{name: string, permissions: list<string>}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(Role::allPermissionKeys())],
        ]);

        return ['name' => $data['name'], 'permissions' => array_values($data['permissions'] ?? [])];
    }
}
