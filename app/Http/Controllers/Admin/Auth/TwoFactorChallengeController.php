<?php

namespace App\Http\Controllers\Admin\Auth;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Security\SignInLimiter;
use App\Security\Totp;
use App\Support\Activity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Second sign-in step for staff with two-factor login turned on.
 *
 * After a few wrong codes the account has to wait (SignInLimiter), whichever IP addresses the codes
 * come from, and the password step starts again.
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

        // One check at a time per account, so codes sent at the same moment are all counted.
        return SignInLimiter::oneCodeAtATime('admin', $admin->id, fn (): RedirectResponse => $this->attempt($request, $admin));
    }

    private function attempt(Request $request, Admin $admin): RedirectResponse
    {
        if (SignInLimiter::codesLocked('admin', $admin->id)) {
            return $this->stop($request);
        }

        if (! $this->passes($admin, (string) $request->input('code'), (string) $request->input('recovery_code'))) {
            Activity::log('admin.two_factor_failed', "Wrong two-factor code for {$admin->name}", $admin);

            if (SignInLimiter::codeFailed('admin', $admin->id)) {
                Activity::log('admin.two_factor_locked', "Two-factor sign-in for {$admin->name} paused for 15 minutes after too many wrong codes", $admin);

                return $this->stop($request);
            }

            return back()->withErrors(['code' => __('That code is not right. Check the time on your phone and try the newest code.')]);
        }

        SignInLimiter::codePassed('admin', $admin->id);
        $remember = (bool) $request->session()->pull('admin.two_factor.remember', false);
        $request->session()->forget('admin.two_factor');

        return LoginController::completeLogin($request, $admin, $remember);
    }

    /**
     * Too many wrong codes: forget the half-done sign-in, so it starts again from the password.
     */
    private function stop(Request $request): RedirectResponse
    {
        $request->session()->forget('admin.two_factor');

        return redirect()->route('admin.login')->withErrors(['email' => __('Too many wrong codes. Wait 15 minutes, then sign in again.')]);
    }

    private function passes(Admin $admin, string $code, string $recoveryCode): bool
    {
        // The same digits with spaces or tabs inside are the same code.
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if ($code !== '') {
            // Cache::add only works once per code, even for two requests at the same moment, so a
            // code that was used cannot be used again.
            return Totp::verify((string) $admin->two_factor_secret, $code)
                && Cache::add("admin.2fa.used.{$admin->id}.{$code}", true, now()->addMinutes(2));
        }

        $recoveryCode = strtolower(trim($recoveryCode));

        if ($recoveryCode === '') {
            return false;
        }

        // Read and remove the code in one locked step, so one recovery code cannot be used twice.
        return DB::transaction(function () use ($admin, $recoveryCode): bool {
            $fresh = Admin::query()->lockForUpdate()->find($admin->id);
            $codes = $fresh?->two_factor_recovery_codes ?? [];

            if (! in_array($recoveryCode, $codes, true)) {
                return false;
            }

            $fresh->forceFill(['two_factor_recovery_codes' => array_values(array_diff($codes, [$recoveryCode]))])->save();

            return true;
        });
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
