<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Admin;
use App\Models\Client;
use App\Security\Captcha;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Security: two-factor sign-in rules and CAPTCHA.
 */
class SecurityController extends Controller
{
    public function edit(Captcha $captcha): View
    {
        $provider = (string) setting('security.captcha_provider');

        return view('admin.settings.security', [
            'captchaProviders' => Captcha::PROVIDERS,
            'captchaForms' => Captcha::FORMS,
            'captchaState' => match (true) {
                ! array_key_exists($provider, Captcha::PROVIDERS) => 'off',
                $captcha->provider() !== null => 'on',
                default => 'check',
            },
            'hasCaptchaSecret' => (string) setting('security.captcha_secret') !== '',
            'staffWithoutTwoFactor' => Admin::query()->where('is_active', true)->whereNull('two_factor_confirmed_at')->count(),
            'clientsWithTwoFactor' => Client::query()->whereNotNull('two_factor_confirmed_at')->count(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'staff_two_factor' => ['required', Rule::in(['optional', 'required'])],
            'client_two_factor' => ['required', Rule::in(['off', 'optional', 'required'])],
            'client_two_factor_methods' => ['array', 'required_unless:client_two_factor,off'],
            'client_two_factor_methods.*' => [Rule::in([Client::TWO_FACTOR_APP, Client::TWO_FACTOR_EMAIL])],
            'captcha_provider' => ['required', Rule::in(['off', ...array_keys(Captcha::PROVIDERS)])],
            'captcha_site_key' => ['nullable', 'string', 'max:255', 'required_unless:captcha_provider,off'],
            'captcha_secret' => ['nullable', 'string', 'max:255'],
            'captcha_forms' => ['array'],
            'captcha_forms.*' => [Rule::in(array_keys(Captcha::FORMS))],
        ], [
            'client_two_factor_methods.required_unless' => __('Choose at least one way for clients to get codes.'),
        ]);

        $secret = filled($data['captcha_secret'] ?? null) ? trim($data['captcha_secret']) : (string) setting('security.captcha_secret');
        $siteKey = trim((string) ($data['captcha_site_key'] ?? ''));

        if ($data['captcha_provider'] !== 'off' && $secret === '') {
            return back()->withInput()->withErrors(['captcha_secret' => __('Enter the secret key.')]);
        }

        // New keys must pass the check on this page again before any form uses them.
        $keysChanged = $data['captcha_provider'] !== setting('security.captcha_provider')
            || $siteKey !== setting('security.captcha_site_key')
            || $secret !== setting('security.captcha_secret');

        $settings->setMany([
            'security.staff_two_factor' => $data['staff_two_factor'],
            'security.client_two_factor' => $data['client_two_factor'],
            'security.client_two_factor_methods' => array_values($data['client_two_factor_methods'] ?? []),
            'security.captcha_provider' => $data['captcha_provider'],
            'security.captcha_site_key' => $siteKey,
            'security.captcha_secret' => $secret,
            'security.captcha_forms' => array_values($data['captcha_forms'] ?? []),
            ...($keysChanged ? ['security.captcha_checked_key' => null] : []),
        ]);

        Activity::log('settings.security', 'Security settings changed');

        return back()->with('status', $keysChanged && $data['captcha_provider'] !== 'off'
            ? __('Saved. Now solve the CAPTCHA below to check the keys and turn it on.')
            : __('Security settings saved.'));
    }

    /**
     * Staff solve the CAPTCHA once with the saved keys. Only then do forms start asking for it.
     */
    public function checkCaptcha(Request $request, Captcha $captcha, Settings $settings): RedirectResponse
    {
        $provider = (string) setting('security.captcha_provider');

        if (! array_key_exists($provider, Captcha::PROVIDERS) || ! $captcha->verify($request, $provider, strict: true)) {
            return back()->with('error', __('The check did not pass. Make sure the site key and secret key belong together and that this domain is allowed for them, then try again.'));
        }

        $settings->set('security.captcha_checked_key', $captcha->siteKey());
        Activity::log('settings.security', 'CAPTCHA checked and turned on');

        return back()->with('status', __('The keys work. CAPTCHA is on for the forms you chose.'));
    }
}
