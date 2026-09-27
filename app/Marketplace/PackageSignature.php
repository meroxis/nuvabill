<?php

namespace App\Marketplace;

use RuntimeException;

/**
 * Ed25519 signatures for marketplace packages. The marketplace store signs every approved
 * package; each copy of Nuvabill checks the signature with the public key in config before it
 * installs anything, so a changed or cracked package cannot be installed with one click.
 */
class PackageSignature
{
    /**
     * The signed text binds the package's name and version to its exact bytes.
     */
    public static function message(string $slug, string $version, string $sha256): string
    {
        return "nuvabill-package\n{$slug}\n{$version}\n".strtolower($sha256);
    }

    public static function sign(string $slug, string $version, string $sha256, string $secretKey): string
    {
        $secret = base64_decode(trim($secretKey), true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The marketplace signing key is not a valid base64 Ed25519 secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached(self::message($slug, $version, $sha256), $secret));
    }

    public static function verify(string $slug, string $version, string $sha256, string $signature, string $publicKey): bool
    {
        $key = base64_decode(trim($publicKey), true);
        $sig = base64_decode(trim($signature), true);

        if ($key === false || $sig === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::message($slug, $version, $sha256), $key);
    }
}
