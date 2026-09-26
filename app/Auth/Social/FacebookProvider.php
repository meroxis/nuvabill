<?php

namespace App\Auth\Social;

use Illuminate\Support\Facades\Http;

/**
 * Sign in with Facebook. Facebook does not say whether an email address is confirmed, so a Facebook
 * sign-in never takes over an existing account with the same email: the client connects Facebook
 * from their Account page after signing in with their password.
 */
class FacebookProvider extends Provider
{
    private const GRAPH = 'https://graph.facebook.com/v23.0';

    public function slug(): string
    {
        return 'facebook';
    }

    public function name(): string
    {
        return 'Facebook';
    }

    public function consoleUrl(): string
    {
        return 'https://developers.facebook.com/apps/';
    }

    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        return 'https://www.facebook.com/v23.0/dialog/oauth?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'response_type' => 'code',
            'scope' => 'email,public_profile',
            'state' => $state,
        ]);
    }

    public function user(string $code, string $codeVerifier): SocialUser
    {
        $token = $this->token($this->json(fn () => Http::acceptJson()->timeout(15)->get(self::GRAPH.'/oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUrl,
            'code' => $code,
        ])));

        $profile = $this->json(fn () => Http::acceptJson()->timeout(15)->get(self::GRAPH.'/me', [
            'fields' => 'id,first_name,last_name,email',
            'access_token' => $token,
            'appsecret_proof' => hash_hmac('sha256', $token, $this->clientSecret),
        ]));

        return new SocialUser(
            id: (string) $profile['id'],
            email: isset($profile['email']) ? strtolower((string) $profile['email']) : null,
            emailVerified: false,
            firstName: (string) ($profile['first_name'] ?? ''),
            lastName: (string) ($profile['last_name'] ?? ''),
        );
    }
}
