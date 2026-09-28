<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\ApiToken;
use Illuminate\Support\Collection;

/**
 * Who can sign in to the admin area, what they may do and how well their accounts are protected;
 * API tokens; and the sign-in protection clients get.
 */
class StaffChecks extends CheckGroup
{
    public function key(): string
    {
        return 'staff';
    }

    public function section(): string
    {
        return self::SECURITY;
    }

    public function title(): string
    {
        return 'Staff and access';
    }

    public function description(): string
    {
        return 'Who can sign in, what they can do, and how well their accounts are protected.';
    }

    public function icon(): string
    {
        return 'users';
    }

    public function run(): array
    {
        $staff = Admin::query()->with(['role', 'passkeys'])->where('is_active', true)->orderBy('name')->get();
        $powerful = $staff->filter(fn (Admin $admin): bool => $this->isPowerful($admin));
        $unprotected = fn (Collection $people): Collection => $people->reject(fn (Admin $admin): bool => $admin->hasTwoFactorEnabled() || $admin->passkeys->isNotEmpty());

        return [
            $this->powerfulTwoFactor($unprotected($powerful), $powerful->count()),
            $this->otherTwoFactor($unprotected($staff->diffKeys($powerful))),
            $this->fullAccess($powerful),
            $this->inactive($staff),
            $this->sampleEmails($staff),
            $this->failedSignIns(),
            $this->unusedTokens(),
            $this->captcha(),
            $this->clientTwoFactor(),
        ];
    }

    /**
     * Owners, and anyone who may manage staff or settings: they can give themselves every other right.
     */
    private function isPowerful(Admin $admin): bool
    {
        return $admin->role?->isOwner() === true || $admin->hasPermission('staff.manage') || $admin->hasPermission('settings.manage');
    }

    /**
     * @param  Collection<int, Admin>  $unprotected
     */
    private function powerfulTwoFactor(Collection $unprotected, int $powerful): CheckResult
    {
        $check = $this->check('staff.powerful_two_factor', 'Staff with full access use two-factor sign-in', weight: 5);

        if ($unprotected->isEmpty()) {
            return $check->passed(':count people with full access, all protected', ['count' => $powerful]);
        }

        return $check->urgent(':names can sign in with only a password', ['names' => $unprotected->pluck('name')->implode(', ')],
            advice: 'A stolen password is enough to take over the whole site. Ask them to turn on two-factor sign-in or add a passkey under their profile, or require it for all staff.',
            items: $this->people($unprotected, 'urgent'),
            fix: $this->requireTwoFactorFix(),
            link: $this->link('admin.settings.staff.index', 'Open staff'),
        );
    }

    /**
     * @param  Collection<int, Admin>  $unprotected
     */
    private function otherTwoFactor(Collection $unprotected): CheckResult
    {
        $check = $this->check('staff.two_factor', 'Other staff use two-factor sign-in');

        if ($unprotected->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(':names can sign in with only a password', ['names' => $unprotected->pluck('name')->implode(', ')],
            advice: 'Staff see client details and invoices. A second sign-in step keeps a leaked password from being enough.',
            items: $this->people($unprotected, 'warning'),
            fix: $this->requireTwoFactorFix(),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function requireTwoFactorFix(): ?array
    {
        return setting('security.staff_two_factor') === 'required'
            ? null
            : $this->fix('staff.require_two_factor', 'Require two-factor sign-in for all staff', confirm: 'Staff without it will be asked to set it up the next time they sign in.');
    }

    /**
     * @param  Collection<int, Admin>  $powerful
     */
    private function fullAccess(Collection $powerful): CheckResult
    {
        $check = $this->check('staff.full_access', 'Only a few people have full access', weight: 1);

        if ($powerful->count() <= 3) {
            return $check->passed(':count people', ['count' => $powerful->count()]);
        }

        return $check->warning(':count people have full access', ['count' => $powerful->count()],
            advice: 'Give "Manage staff and roles" and "Change system settings, gateways and email templates" only to people who need them. Anyone with them can give themselves every other right.',
            items: $this->people($powerful, null),
            link: $this->link('admin.settings.roles.index', 'Open roles'),
        );
    }

    /**
     * @param  Collection<int, Admin>  $staff
     */
    private function inactive(Collection $staff): CheckResult
    {
        $check = $this->check('staff.inactive', 'No active staff account unused for 90 days');
        $since = now()->subDays(90);
        $idle = $staff->filter(fn (Admin $admin): bool => $admin->last_login_at === null ? $admin->created_at?->lt($since) === true : $admin->last_login_at->lt($since))->values();

        if ($idle->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(':names did not sign in for 90 days', ['names' => $idle->pluck('name')->implode(', ')],
            advice: 'Accounts nobody uses are easy to miss when a password leaks. Switch them off; you can switch them on again at any time.',
            items: $idle->map(fn (Admin $admin): array => [
                'label' => $admin->name,
                'value' => $admin->last_login_at ? __('Last sign-in :date', ['date' => $admin->last_login_at->translatedFormat('d M Y')]) : __('Never signed in'),
                'status' => 'warning',
            ])->all(),
            fix: $this->fix('staff.disable_inactive', 'Switch them off', ['ids' => $idle->pluck('id')->all()], confirm: 'They can no longer sign in until you switch them on again.'),
        );
    }

    /**
     * @param  Collection<int, Admin>  $staff
     */
    private function sampleEmails(Collection $staff): CheckResult
    {
        $check = $this->check('staff.sample_email', 'No staff account uses a sample email address', weight: 1);
        $samples = $staff->filter(fn (Admin $admin): bool => (bool) preg_match('/@(example\.(com|net|org)|localhost|[^@]+\.(test|invalid|local))$/i', $admin->email))->values();

        if ($samples->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(':names cannot get password reset emails', ['names' => $samples->pluck('name')->implode(', ')],
            advice: 'A sample address cannot receive emails, so the password cannot be reset and sign-in codes cannot arrive.',
            items: $this->people($samples, 'warning'),
            link: $this->link('admin.settings.staff.index', 'Open staff'),
        );
    }

    private function failedSignIns(): CheckResult
    {
        $check = $this->check('staff.failed_signins', 'Failed staff sign-ins look normal');
        $failed = ActivityLog::query()->where('action', 'admin.login_failed')->where('created_at', '>=', now()->subDay())->count();

        if ($failed <= 30) {
            return $check->passed(':count in 24 hours', ['count' => $failed]);
        }

        return $check->warning(':count failed staff sign-ins in 24 hours', ['count' => $failed],
            advice: 'Someone may be guessing passwords. Sign-ins are slowed down after a few tries, but make sure every staff member uses two-factor sign-in.',
            link: $this->link('admin.settings.activity', 'Open the activity log'),
        );
    }

    private function unusedTokens(): CheckResult
    {
        $check = $this->check('api.write_tokens', 'API tokens that can change data are in use');
        $since = now()->subDays(60);
        $unused = ApiToken::query()->with('admin')->where('can_write', true)
            ->where(fn ($query) => $query->where('last_used_at', '<', $since)->orWhere(fn ($never) => $never->whereNull('last_used_at')->where('created_at', '<', $since)))
            ->get();

        if ($unused->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(':count tokens were not used for 60 days', ['count' => $unused->count()],
            advice: 'A token nobody uses is a door nobody watches. Delete the ones you do not need; you can make a new one at any time.',
            items: $unused->map(fn (ApiToken $token): array => [
                'label' => $token->name,
                'value' => ($token->admin?->name ?? '—').' · '.($token->last_used_at ? __('Last used :date', ['date' => $token->last_used_at->translatedFormat('d M Y')]) : __('Never used')),
                'status' => 'warning',
            ])->all(),
            fix: $this->fix('api.delete_tokens', 'Delete them', ['ids' => $unused->pluck('id')->all()], confirm: 'Programs that still use these tokens will stop working.', danger: true),
        );
    }

    private function captcha(): CheckResult
    {
        $check = $this->check('clients.captcha', 'CAPTCHA protects client sign-in and sign-up');
        $forms = (array) setting('security.captcha_forms');

        if (setting('security.captcha_provider') !== 'off' && in_array('client_login', $forms, true) && in_array('client_register', $forms, true)) {
            return $check->passed();
        }

        return $check->warning('Robots can try passwords and make fake accounts',
            advice: 'Turn on a CAPTCHA for client sign-in and sign-up in Settings → Security. Cloudflare Turnstile is free and invisible to most people.',
            link: $this->link('admin.settings.security.edit', 'Open Settings → Security'),
        );
    }

    private function clientTwoFactor(): CheckResult
    {
        $check = $this->check('clients.two_factor', 'Clients can turn on two-factor sign-in', weight: 1);

        if (setting('security.client_two_factor') !== 'off') {
            return $check->passed();
        }

        return $check->warning('Two-factor sign-in is off for clients',
            link: $this->link('admin.settings.security.edit', 'Open Settings → Security'),
        );
    }

    /**
     * @param  Collection<int, Admin>  $people
     * @return list<array<string, mixed>>
     */
    private function people(Collection $people, ?string $status): array
    {
        return $people->map(fn (Admin $admin): array => array_filter([
            'label' => $admin->name,
            'value' => $admin->email.' · '.($admin->role?->name ?? '—'),
            'status' => $status,
        ]))->values()->all();
    }
}
