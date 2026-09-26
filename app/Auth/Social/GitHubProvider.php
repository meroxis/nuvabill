<?php

namespace App\Auth\Social;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Sign in with GitHub. Only a verified email address is used.
 */
class GitHubProvider extends Provider
{
    public function slug(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function consoleUrl(): string
    {
        return 'https://github.com/settings/developers';
    }

    public function authorizeUrl(string $state, string $codeVerifier): string
    {
        return 'https://github.com/login/oauth/authorize?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUrl,
            'scope' => 'read:user user:email',
            'state' => $state,
        ]);
    }

    public function user(string $code, string $codeVerifier): SocialUser
    {
        $token = $this->token($this->json(fn () => Http::asForm()->acceptJson()->timeout(15)->post('https://github.com/login/oauth/access_token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUrl,
        ])));

        $profile = $this->json(fn () => $this->api($token)->get('https://api.github.com/user'));
        $emails = $this->json(fn () => $this->api($token)->get('https://api.github.com/user/emails'));

        $verified = collect($emails)->filter(fn (mixed $email): bool => is_array($email) && ($email['verified'] ?? false) === true);
        $email = $verified->firstWhere('primary', true)['email'] ?? $verified->first()['email'] ?? null;

        [$first, $last] = SocialUser::splitName((string) ($profile['name'] ?? '') ?: (string) ($profile['login'] ?? ''));

        return new SocialUser(
            id: (string) $profile['id'],
            email: $email !== null ? strtolower((string) $email) : null,
            emailVerified: $email !== null,
            firstName: $first,
            lastName: $last,
        );
    }

    private function api(string $token): PendingRequest
    {
        return Http::withToken($token)->timeout(15)->withHeaders([
            'Accept' => 'application/vnd.github+json',
            'X-GitHub-Api-Version' => '2022-11-28',
        ]);
    }
}
