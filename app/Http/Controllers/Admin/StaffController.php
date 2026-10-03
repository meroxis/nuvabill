<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
        $this->onlyOwnersTouchOwners($request, null, (int) $data['role_id']);

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
        $active = (bool) ($data['is_active'] ?? $admin->is_active);
        $staysOwner = Role::find($data['role_id'])?->isOwner() === true;

        if ($admin->is($request->user('admin')) && ! $active) {
            throw ValidationException::withMessages(['is_active' => __('You cannot turn off your own account.')]);
        }

        // At least one active owner must stay, whether the owner loses the role or is turned off.
        if ($admin->is_active && $admin->role?->isOwner() && (! $staysOwner || ! $active) && $this->ownerCount() <= 1) {
            throw ValidationException::withMessages([$active ? 'role_id' : 'is_active' => __('This is the last owner. Give someone else the Owner role first.')]);
        }

        $this->onlyOwnersTouchOwners($request, $admin, (int) $data['role_id']);

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $admin->fill($data);

        // A new password also ends remember-me logins; other sessions end through auth.session.
        if ($admin->isDirty('password')) {
            $admin->setRememberToken(Str::random(60));
        }

        $admin->save();

        // Staff editing their own account here keep this session: the guard holds the new password.
        if ($admin->is($request->user('admin'))) {
            Auth::guard('admin')->setUser($admin);
        }

        Activity::log('staff.updated', "Staff account for {$admin->name} updated", $admin);

        return redirect()->route('admin.settings.staff.index')->with('status', __('Staff member saved.'));
    }

    /**
     * Staff managers who are not owners cannot make owners or change an owner's account (email,
     * password, role or sign-in), so they cannot take over or lock out an owner.
     */
    private function onlyOwnersTouchOwners(Request $request, ?Admin $admin, int $roleId): void
    {
        if ($request->user('admin')->role?->isOwner() === true) {
            return;
        }

        if ($admin?->role?->isOwner() === true || Role::find($roleId)?->isOwner() === true) {
            throw ValidationException::withMessages(['role_id' => __('Only an owner can give the Owner role or change an owner account.')]);
        }
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
