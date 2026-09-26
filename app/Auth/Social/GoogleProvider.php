<?php

namespace App\Auth\Social;

use Illuminate\Support\Facades\Http;

/**
 * Sign in with Google (OpenID Connect, with PKCE).
 */
class GoogleProvider extends Provider
{
    public function slug(): string
    {
        return 'google';
    }

    public function name(): string
    {
        return 'Google';
    }

    public function consoleUrl(): string
    {
        return 'https://console.cloud.google.com/apis/credentials';
    }

    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
        ]);
    }

    public function user(string $code, string $codeVerifier): SocialUser
    {
        $token = $this->token($this->json(fn () => Http::asForm()->acceptJson()->timeout(15)->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'grant_type' => 'authorization_code',
            'code_verifier' => $codeVerifier,
        ])));

        $profile = $this->json(fn () => Http::withToken($token)->acceptJson()->timeout(15)->get('https://openidconnect.googleapis.com/v1/userinfo'));

        return new SocialUser(
            id: (string) $profile['sub'],
            email: isset($profile['email']) ? strtolower((string) $profile['email']) : null,
            emailVerified: ($profile['email_verified'] ?? false) === true,
            firstName: (string) ($profile['given_name'] ?? ''),
            lastName: (string) ($profile['family_name'] ?? ''),
        );
    }
}
