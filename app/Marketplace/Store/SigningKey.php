<?php

namespace App\Marketplace\Store;

use RuntimeException;

/**
 * The marketplace store's Ed25519 signing key. The secret half is made on the store's own server
 * (php artisan nuvabill:marketplace-key) and never leaves it; the public half goes into every
 * copy of Nuvabill (config nuvabill.marketplace.public_key).
 */
class SigningKey
{
    public function path(): string
    {
        return (string) (config('nuvabill.marketplace.signing_key_path') ?: storage_path('app/private/marketplace-signing.key'));
    }

    public function exists(): bool
    {
        return is_file($this->path());
    }

    public function secret(): string
    {
        if (! $this->exists()) {
            throw new RuntimeException('The marketplace has no signing key yet. Run php artisan nuvabill:marketplace-key on the store server.');
        }

        return trim((string) file_get_contents($this->path()));
    }

    public function publicKey(): string
    {
        $secret = base64_decode($this->secret(), true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The marketplace signing key file is damaged.');
        }

        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($secret));
    }

    /**
     * Make a new key pair and return the public half. Refuses to replace an existing key, because
     * every copy of Nuvabill trusts the current one.
     */
    public function generate(): string
    {
        if ($this->exists()) {
            throw new RuntimeException('A marketplace signing key already exists. Replacing it would stop every site from installing packages.');
        }

        $pair = sodium_crypto_sign_keypair();
        $directory = dirname($this->path());

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        file_put_contents($this->path(), base64_encode(sodium_crypto_sign_secretkey($pair)));
        @chmod($this->path(), 0600);

        return base64_encode(sodium_crypto_sign_publickey($pair));
    }
}
