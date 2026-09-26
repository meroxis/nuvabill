<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Security\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Second sign-in step for staff with two-factor login turned on.
 */
class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if ($this->pendingAdmin($request) === null) {
            return redirect()->route('admin.login');
        }

        return view('admin.auth.two-factor');
    }

    public function store(Request $request): RedirectResponse
    {
        $admin = $this->pendingAdmin($request);

        if ($admin === null) {
            return redirect()->route('admin.login')->withErrors(['email' => __('That took too long. Sign in again.')]);
        }

        $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:20'],
        ]);

        if (! $this->passes($admin, (string) $request->input('code'), (string) $request->input('recovery_code'))) {
            return back()->withErrors(['code' => __('That code is not right. Check the time on your phone and try the newest code.')]);
        }

        $remember = (bool) $request->session()->pull('admin.two_factor.remember', false);
        $request->session()->forget('admin.two_factor');

        return LoginController::completeLogin($request, $admin, $remember);
    }

    private function passes(Admin $admin, string $code, string $recoveryCode): bool
    {
        if ($code !== '') {
            $cacheKey = "admin.2fa.used.{$admin->id}.{$code}";

            if (Cache::has($cacheKey) || ! Totp::verify((string) $admin->two_factor_secret, $code)) {
                return false;
            }

            Cache::put($cacheKey, true, now()->addMinutes(2));

            return true;
        }

        $recoveryCode = strtolower(trim($recoveryCode));
        $codes = $admin->two_factor_recovery_codes ?? [];

        if ($recoveryCode === '' || ! in_array($recoveryCode, $codes, true)) {
            return false;
        }

        $admin->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$recoveryCode]))])->save();

        return true;
    }

    private function pendingAdmin(Request $request): ?Admin
    {
        $pending = $request->session()->get('admin.two_factor');

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        $admin = Admin::find($pending['id'] ?? null);

        return $admin?->is_active && $admin->hasTwoFactorEnabled() ? $admin : null;
    }
}
