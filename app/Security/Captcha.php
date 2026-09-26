<?php

namespace App\Security;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * "Are you a person?" checks on public forms, with Cloudflare Turnstile, Google reCAPTCHA (v2) or hCaptcha.
 *
 * A CAPTCHA only runs after staff solved it once on the Security settings page with the saved keys,
 * so wrong keys can never lock anyone out of the sign-in page.
 */
class Captcha
{
    /**
     * @var array<string, array{name: string, script: string, class: string, field: string, verify: string, hosts: list<string>, console: string}>
     */
    public const PROVIDERS = [
        'turnstile' => [
            'name' => 'Cloudflare Turnstile',
            'script' => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            'class' => 'cf-turnstile',
            'field' => 'cf-turnstile-response',
            'verify' => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
            'hosts' => ['https://challenges.cloudflare.com'],
            'console' => 'https://dash.cloudflare.com/?to=/:account/turnstile',
        ],
        'recaptcha' => [
            'name' => 'Google reCAPTCHA (v2 checkbox)',
            'script' => 'https://www.google.com/recaptcha/api.js',
            'class' => 'g-recaptcha',
            'field' => 'g-recaptcha-response',
            'verify' => 'https://www.google.com/recaptcha/api/siteverify',
            'hosts' => ['https://www.google.com', 'https://www.gstatic.com', 'https://www.recaptcha.net'],
            'console' => 'https://www.google.com/recaptcha/admin',
        ],
        'hcaptcha' => [
            'name' => 'hCaptcha',
            'script' => 'https://js.hcaptcha.com/1/api.js',
            'class' => 'h-captcha',
            'field' => 'h-captcha-response',
            'verify' => 'https://api.hcaptcha.com/siteverify',
            'hosts' => ['https://hcaptcha.com', 'https://*.hcaptcha.com'],
            'console' => 'https://dashboard.hcaptcha.com/sites',
        ],
    ];

    /**
     * Forms that can ask for a CAPTCHA.
     *
     * @var array<string, string>
     */
    public const FORMS = [
        'client_login' => 'Client sign-in',
        'client_register' => 'Create an account',
        'password_reset' => 'Forgot password (clients and staff)',
        'checkout' => 'Checkout',
        'tickets' => 'New support ticket',
        'admin_login' => 'Staff sign-in',
    ];

    /**
     * The provider in use, or null when CAPTCHA is off or its keys have not passed the check yet.
     */
    public function provider(): ?string
    {
        $provider = (string) setting('security.captcha_provider');

        if (! array_key_exists($provider, self::PROVIDERS) || $this->siteKey() === '' || (string) setting('security.captcha_secret') === '') {
            return null;
        }

        return setting('security.captcha_checked_key') === $this->siteKey() ? $provider : null;
    }

    public function protects(string $form): bool
    {
        return $this->provider() !== null && in_array($form, (array) setting('security.captcha_forms'), true);
    }

    public function siteKey(): string
    {
        return (string) setting('security.captcha_site_key');
    }

    /**
     * Details for a provider (the active one by default).
     *
     * @return array{name: string, script: string, class: string, field: string, verify: string, hosts: list<string>, console: string}|null
     */
    public function details(?string $provider = null): ?array
    {
        return self::PROVIDERS[$provider ?? $this->provider() ?? ''] ?? null;
    }

    /**
     * Check the token the widget added to the form. If the provider cannot be reached, the form is
     * let through (and logged): sign-in pages keep working, and rate limits still apply.
     * In strict mode (the settings check) only a real "success" counts.
     */
    public function verify(Request $request, ?string $provider = null, ?string $secret = null, bool $strict = false): bool
    {
        $details = $this->details($provider);

        if ($details === null) {
            return ! $strict;
        }

        $token = $request->input($details['field']);

        if (! is_string($token) || $token === '' || strlen($token) > 4096) {
            return false;
        }

        try {
            $response = Http::asForm()->acceptJson()->timeout(8)->post($details['verify'], [
                'secret' => $secret ?? (string) setting('security.captcha_secret'),
                'response' => $token,
                'remoteip' => $request->ip(),
            ]);
        } catch (ConnectionException $exception) {
            Log::warning('CAPTCHA check skipped: '.$details['name'].' could not be reached.', ['error' => $exception->getMessage()]);

            return ! $strict;
        }

        if ($response->serverError()) {
            Log::warning('CAPTCHA check skipped: '.$details['name'].' returned HTTP '.$response->status().'.');

            return ! $strict;
        }

        return $response->json('success') === true;
    }

    /**
     * Hosts the page must allow in its Content-Security-Policy for the widget.
     *
     * @return list<string>
     */
    public function cspHosts(): array
    {
        return $this->details()['hosts'] ?? [];
    }
}
