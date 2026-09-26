<?php

namespace App\Auth\Social;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * An OAuth 2 sign-in provider (Google, GitHub, Facebook).
 */
abstract class Provider
{
    public function __construct(
        protected string $clientId,
        protected string $clientSecret,
        protected string $redirectUrl,
    ) {}

    abstract public function slug(): string;

    abstract public function name(): string;

    /**
     * Where the site owner creates the app and gets the client ID and secret.
     */
    abstract public function consoleUrl(): string;

    /**
     * The provider's sign-in page the client is sent to.
     */
    abstract public function authorizeUrl(string $state, string $codeVerifier): string;

    /**
     * Trade the code from the callback for the client's profile.
     *
     * @throws RuntimeException when the provider does not confirm the sign-in
     */
    abstract public function user(string $code, string $codeVerifier): SocialUser;

    /**
     * @param  callable(): Response  $request
     * @return array<string, mixed>
     */
    protected function json(callable $request): array
    {
        try {
            $response = $request();
        } catch (ConnectionException $exception) {
            throw new RuntimeException($this->name().': '.$exception->getMessage(), previous: $exception);
        }

        $data = $response->json();

        if (! $response->successful() || ! is_array($data) || isset($data['error'])) {
            throw new RuntimeException($this->name().' returned HTTP '.$response->status().': '.substr($response->body(), 0, 300));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function token(array $data): string
    {
        $token = $data['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new RuntimeException($this->name().' did not return an access token.');
        }

        return $token;
    }
}
