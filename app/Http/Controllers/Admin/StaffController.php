<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('admin.settings.staff.index', [
            'staff' => Admin::query()->with('role')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        return view('admin.settings.staff.form', [
            'admin' => new Admin(['is_active' => true]),
            'roles' => Role::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $admin = Admin::create($data);
        Activity::log('staff.created', "Staff account for {$admin->name} created", $admin);

        return redirect()->route('admin.settings.staff.index')->with('status', __('Staff member added. Share the password with them safely.'));
    }

    public function edit(Admin $admin): View
    {
        return view('admin.settings.staff.form', [
            'admin' => $admin,
            'roles' => Role::query()->orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    public function update(Request $request, Admin $admin): RedirectResponse
    {
        $data = $this->validated($request, $admin);

        if ($admin->is($request->user('admin')) && ! $data['is_active']) {
            throw ValidationException::withMessages(['is_active' => __('You cannot turn off your own account.')]);
        }

        if ($admin->role?->isOwner() && ! Role::find($data['role_id'])?->isOwner() && $this->ownerCount() <= 1) {
            throw ValidationException::withMessages(['role_id' => __('This is the last owner. Give someone else the Owner role first.')]);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $admin->update($data);
        Activity::log('staff.updated', "Staff account for {$admin->name} updated", $admin);

        return redirect()->route('admin.settings.staff.index')->with('status', __('Staff member saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Admin $admin = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('admins', 'email')->ignore($admin?->id)],
            'role_id' => ['required', 'exists:roles,id'],
            'password' => [$admin ? 'nullable' : 'required', Password::min(10)],
            'is_active' => ['boolean'],
        ]);
    }

    private function ownerCount(): int
    {
        return Admin::query()->where('is_active', true)->get()->filter(fn (Admin $admin): bool => $admin->role?->isOwner() === true)->count();
    }
}
