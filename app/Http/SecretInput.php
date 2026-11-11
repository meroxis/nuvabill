<?php

namespace App\Http;

use App\Auth\Social\SocialLogin;
use App\Provisioning\OrderFields;
use App\Security\OwnerCheck;

/**
 * Form fields that hold a secret. They are never kept in the session when a form comes back
 * after an error (bootstrap/app.php dontFlash, and the CAPTCHA check), so a copy of the sessions
 * table or the session files does not hold them.
 */
final class SecretInput
{
    /**
     * Request keys; a dot reaches into an array, as in "google.client_secret".
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_values(array_unique([
            // Laravel's own list.
            'password', 'password_confirmation', 'current_password',
            // Passwords typed on the Account page.
            ...OwnerCheck::PASSWORD_FIELDS,
            // Server API tokens, BMC logins of dedicated servers, and root passwords staff type.
            'api_token', 'bmc_username', 'bmc_password', 'root_password',
            // The secret order form fields of server modules.
            OrderFields::SECRET,
            // API keys, bot tokens, app secrets, captcha secrets, EPP codes and license keys.
            // The password reset forms take their token from the link, never from old input.
            'key', 'token', 'app_secret', 'captcha_secret', 'epp_code', 'license_key',
            // The setup code the web installer asks for.
            'setup_code',
            // The client secrets of social login.
            ...array_map(fn (string $slug): string => $slug.'.client_secret', array_keys(SocialLogin::PROVIDERS)),
        ]));
    }
}
