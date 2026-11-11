<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Role;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

    public function create(Request $request): View
    {
        return view('admin.settings.staff.form', [
            'admin' => new Admin(['is_active' => true]),
            'roles' => $this->rolesToGive($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->onlyOwnersTouchOwners($request, null, $data);

        $admin = Admin::create($data);
        Activity::log('staff.created', "Staff account for {$admin->name} created", $admin);

        return redirect()->route('admin.settings.staff.index')->with('status', __('Staff member added. Share the password with them safely.'));
    }

    public function edit(Request $request, Admin $admin): View
    {
        return view('admin.settings.staff.form', [
            'admin' => $admin,
            'roles' => $this->rolesToGive($request, $admin),
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

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $this->onlyOwnersTouchOwners($request, $admin, $data);
        $this->confirmOwnSignInChange($request, $admin, $data);

        $admin->fill($data);

        // A new password also ends remember-me logins; other sessions end through auth.session.
        $newPassword = $admin->isDirty('password');
        if ($newPassword) {
            $admin->setRememberToken(Str::random(60));
        }

        $admin->save();

        // Setting someone's password is a reset of their sign-in, so their API keys stop working too.
        if ($newPassword) {
            $admin->revokeApiKeys('password set in staff settings');
        }

        // Staff editing their own account here keep this session: the guard holds the new password.
        if ($admin->is($request->user('admin'))) {
            Auth::guard('admin')->setUser($admin);
        }

        Activity::log('staff.updated', "Staff account for {$admin->name} updated", $admin);

        return redirect()->route('admin.settings.staff.index')->with('status', __('Staff member saved.'));
    }

    /**
     * Staff who change their own password or email here type their current password first, as on
     * the profile page, so a stolen session cannot take the account over. Wrong passwords share
     * the limit of the other password checks.
     *
     * @param  array<string, mixed>  $data
     */
    private function confirmOwnSignInChange(Request $request, Admin $admin, array $data): void
    {
        if (! $admin->is($request->user('admin')) || ! $this->changesSignIn($admin, $data)) {
            return;
        }

        $key = 'staff-own-sign-in:'.$admin->getKey();

        if (RateLimiter::tooManyAttempts($key, 6)) {
            $seconds = RateLimiter::availableIn($key);

            throw ValidationException::withMessages(['current_password' => trans_choice('Too many tries. Wait :seconds second, then try again.|Too many tries. Wait :seconds seconds, then try again.', $seconds, ['seconds' => $seconds])]);
        }

        if (! Hash::check((string) $request->input('current_password'), (string) $admin->password)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['current_password' => __('To change your own email or password, type your current password.')]);
        }

        RateLimiter::clear($key);
    }

    /**
     * Staff managers who are not owners cannot make owners or change an owner's account (email,
     * password, role or sign-in), so they cannot take over or lock out an owner.
     *
     * They also cannot get more permissions than they have: they keep their own role, only give
     * roles whose permissions they all have themselves, and cannot set the email or password of
     * staff whose role has more.
     *
     * @param  array<string, mixed>  $data
     */
    private function onlyOwnersTouchOwners(Request $request, ?Admin $admin, array $data): void
    {
        $staff = $request->user('admin');

        if ($staff->role?->isOwner() === true) {
            return;
        }

        $role = Role::find((int) $data['role_id']);

        if ($admin?->role?->isOwner() === true || $role?->isOwner() === true) {
            throw ValidationException::withMessages(['role_id' => __('Only an owner can give the Owner role or change an owner account.')]);
        }

        if ($admin === null || (int) $admin->role_id !== (int) $data['role_id']) {
            if ($admin?->is($staff) === true) {
                throw ValidationException::withMessages(['role_id' => __('You cannot change your own role. Ask an owner.')]);
            }

            if (! $staff->holdsAllOf($role)) {
                throw ValidationException::withMessages(['role_id' => __('You can only give a role when you have all of its permissions yourself.')]);
            }
        }

        if ($admin !== null && ! $admin->is($staff) && $this->changesSignIn($admin, $data) && ! $staff->holdsAllOf($admin->role)) {
            throw ValidationException::withMessages([
                isset($data['password']) ? 'password' : 'email' => __('Only an owner can change the email or password of staff who have more permissions than you.'),
            ]);
        }
    }

    /**
     * Whether the form sets a new password or another email address for the staff member.
     *
     * @param  array<string, mixed>  $data
     */
    private function changesSignIn(Admin $admin, array $data): bool
    {
        return isset($data['password']) || mb_strtolower((string) $data['email']) !== mb_strtolower((string) $admin->email);
    }

    /**
     * The roles the form offers: every role for owners. Other staff managers get the roles whose
     * permissions they all have themselves, plus the role the staff member has now. On their own
     * account that is only their own role.
     *
     * @return array<int, string>
     */
    private function rolesToGive(Request $request, ?Admin $admin = null): array
    {
        $staff = $request->user('admin');

        return Role::query()->orderBy('name')->get()
            ->filter(fn (Role $role): bool => $staff->role?->isOwner() === true
                || (int) $role->getKey() === (int) $admin?->role_id
                || ($admin?->is($staff) !== true && $staff->holdsAllOf($role)))
            ->pluck('name', 'id')
            ->all();
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
