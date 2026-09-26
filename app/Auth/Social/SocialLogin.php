<?php

namespace App\Auth\Social;

/**
 * The sign-in providers staff switch on in Settings → Social login.
 */
class SocialLogin
{
    /**
     * @var array<string, class-string<Provider>>
     */
    public const PROVIDERS = [
        'google' => GoogleProvider::class,
        'github' => GitHubProvider::class,
        'facebook' => FacebookProvider::class,
    ];

    /**
     * Saved settings for one provider.
     *
     * @return array{enabled: bool, client_id: string, client_secret: string}
     */
    public function config(string $slug): array
    {
        $config = (array) setting('social.'.$slug);

        return [
            'enabled' => (bool) ($config['enabled'] ?? false),
            'client_id' => (string) ($config['client_id'] ?? ''),
            'client_secret' => (string) ($config['client_secret'] ?? ''),
        ];
    }

    /**
     * A provider that is switched on and has its keys, or null.
     */
    public function provider(string $slug): ?Provider
    {
        $class = self::PROVIDERS[$slug] ?? null;
        $config = $this->config($slug);

        if ($class === null || ! $config['enabled'] || $config['client_id'] === '' || $config['client_secret'] === '') {
            return null;
        }

        return new $class($config['client_id'], $config['client_secret'], self::callbackUrl($slug));
    }

    /**
     * Providers clients can use now, in display order.
     *
     * @return array<string, Provider>
     */
    public function enabled(): array
    {
        $providers = [];

        foreach (array_keys(self::PROVIDERS) as $slug) {
            if ($provider = $this->provider($slug)) {
                $providers[$slug] = $provider;
            }
        }

        return $providers;
    }

    /**
     * A provider object for showing its name and console link, even when it is switched off.
     */
    public function describe(string $slug): Provider
    {
        $class = self::PROVIDERS[$slug];

        return new $class('', '', self::callbackUrl($slug));
    }

    /**
     * The address staff paste into the provider's app settings ("Authorized redirect URI").
     */
    public static function callbackUrl(string $slug): string
    {
        return route('client.social.callback', $slug);
    }
}
