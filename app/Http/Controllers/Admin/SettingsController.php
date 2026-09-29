<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Activity;
use App\Support\Locales;
use App\Support\Settings;
use App\Support\SettingsMenu;
use App\Support\Themes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class SettingsController extends Controller
{
    /**
     * Currencies offered in v0.1. All use two decimal places, which is how amounts are stored.
     *
     * @var list<string>
     */
    public const CURRENCIES = [
        'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'CZK', 'RON', 'HUF',
        'TRY', 'AED', 'SAR', 'QAR', 'IQD', 'EGP', 'MAD', 'INR', 'PKR', 'BDT', 'IDR', 'MYR', 'SGD', 'HKD',
        'PHP', 'THB', 'CNY', 'BRL', 'MXN', 'ARS', 'ZAR', 'NGN', 'KES', 'UAH',
    ];

    /**
     * Every settings page in its group. On phones this is where Settings starts.
     */
    public function index(Request $request): View
    {
        $admin = $request->user('admin');
        abort_unless($admin->hasPermission('settings.manage') || $admin->hasPermission('staff.manage'), 403);

        return view('admin.settings.index', ['cards' => SettingsMenu::cards($admin)]);
    }

    public function edit(Themes $themes): View
    {
        return view('admin.settings.general', [
            'settings' => app(Settings::class)->all(),
            'currencies' => array_combine(self::CURRENCIES, self::CURRENCIES),
            'themes' => $themes->all()->map(fn (array $theme): string => $theme['name'])->all(),
            'languages' => collect(Locales::ALL)->map(function (array $locale, string $code): string {
                $name = Locales::displayName($code);

                return mb_strtolower($name) === mb_strtolower($locale['native']) ? $locale['native'] : "{$locale['native']} · {$name}";
            })->all(),
            'cronCommand' => '* * * * * cd '.base_path().' && '.PHP_BINARY.' artisan schedule:run >> /dev/null 2>&1',
        ]);
    }

    public function update(Request $request, Settings $settings, Themes $themes): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'company_email' => ['required', 'email', 'max:190'],
            'company_phone' => ['nullable', 'string', 'max:60'],
            'company_address' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', Rule::in(self::CURRENCIES)],
            'invoice_prefix' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z0-9_\-\/]*$/'],
            'renewal_days_before' => ['required', 'integer', 'between:0,60'],
            'payment_terms_days' => ['required', 'integer', 'between:0,90'],
            'automation_enabled' => ['boolean'],
            'reminder_days' => ['nullable', 'string', 'max:40', 'regex:/^[\d,\s]*$/'],
            'suspend_days' => ['required', 'integer', 'between:0,90'],
            'terminate_days' => ['required', 'integer', 'between:0,365'],
            'accent' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'theme' => ['required', Rule::in($themes->all()->keys()->all())],
            'terms_url' => ['nullable', 'url', 'max:255'],
            'privacy_url' => ['nullable', 'url', 'max:255'],
            'locale_default' => ['required', Rule::in(array_keys(Locales::ALL))],
            'locale_enabled' => ['nullable', 'array'],
            'locale_enabled.*' => [Rule::in(array_keys(Locales::ALL))],
        ]);

        $settings->setMany([
            'company.name' => $data['company_name'],
            'company.email' => $data['company_email'],
            'company.phone' => $data['company_phone'] ?? '',
            'company.address' => $data['company_address'] ?? '',
            'billing.currency' => $data['currency'],
            'billing.invoice_prefix' => $data['invoice_prefix'] ?? '',
            'billing.renewal_days_before' => (int) $data['renewal_days_before'],
            'billing.payment_terms_days' => (int) $data['payment_terms_days'],
            'automation.enabled' => $request->boolean('automation_enabled'),
            'automation.reminder_days' => collect(explode(',', (string) ($data['reminder_days'] ?? '')))
                ->map(fn (string $day): int => (int) trim($day))
                ->filter(fn (int $day): bool => $day > 0)
                ->unique()->sort()->values()->all(),
            'automation.suspend_days' => (int) $data['suspend_days'],
            'automation.terminate_days' => (int) $data['terminate_days'],
            'branding.accent' => strtoupper($data['accent']),
            'theme.active' => $data['theme'],
            'orders.accept_terms_url' => $data['terms_url'] ?? '',
            'company.privacy_url' => $data['privacy_url'] ?? '',
            'locale.default' => $data['locale_default'],
            'locale.enabled' => array_values(array_unique([$data['locale_default'], ...($data['locale_enabled'] ?? [])])),
        ]);

        Activity::log('settings.updated', 'System settings changed');

        return back()->with('status', __('Settings saved.'));
    }

    public function updateMail(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'mailer' => ['required', Rule::in(['smtp', 'sendmail', 'log'])],
            'host' => ['nullable', 'required_if:mailer,smtp', 'string', 'max:190'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:190'],
            'encryption' => ['nullable', Rule::in(['tls', 'ssl', 'none'])],
            'from_address' => ['required', 'email', 'max:190'],
            'from_name' => ['required', 'string', 'max:120'],
        ]);

        $values = [
            'mail.mailer' => $data['mailer'],
            'mail.host' => $data['host'] ?? '',
            'mail.port' => (int) ($data['port'] ?? 587),
            'mail.username' => $data['username'] ?? '',
            'mail.encryption' => $data['encryption'] ?? 'tls',
            'mail.from_address' => $data['from_address'],
            'mail.from_name' => $data['from_name'],
        ];

        if (filled($data['password'] ?? null)) {
            $values['mail.password'] = $data['password'];
        }

        $settings->setMany($values);
        Activity::log('settings.mail', 'Email sending settings changed');

        return back()->with('status', __('Email settings saved. Send a test email to check them.'));
    }

    public function testMail(Request $request): RedirectResponse
    {
        $admin = $request->user('admin');

        try {
            Mail::raw(__('This is a test email from :company. Your email settings work.', ['company' => setting('company.name')]), function ($message) use ($admin): void {
                $message->to($admin->email, $admin->name)->subject(__('Test email from :product', ['product' => 'Nuvabill']));
            });
        } catch (Throwable $exception) {
            return back()->with('error', __('The test email failed: :error', ['error' => $exception->getMessage()]));
        }

        return back()->with('status', __('Test email sent to :email. If it does not arrive, check the spam folder.', ['email' => $admin->email]));
    }
}
