<?php

namespace App\Updates;

use RuntimeException;

/**
 * Ed25519 signatures for release zips (libsodium, built into PHP).
 */
class Signature
{
    /**
     * @return array{public: string, secret: string} Both base64 encoded.
     */
    public static function generateKeyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => base64_encode(sodium_crypto_sign_publickey($pair)),
            'secret' => base64_encode(sodium_crypto_sign_secretkey($pair)),
        ];
    }

    public static function sign(string $version, string $zipPath, string $secretKey): string
    {
        $secret = base64_decode($secretKey, true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The signing key is not a valid base64 Ed25519 secret key.');
        }

        $message = Release::signedMessage($version, (string) hash_file('sha256', $zipPath));

        return base64_encode(sodium_crypto_sign_detached($message, $secret));
    }

    public static function verify(string $version, string $zipPath, string $signature, string $publicKey): bool
    {
        $key = base64_decode(trim($publicKey), true);
        $sig = base64_decode(trim($signature), true);

        if ($key === false || $sig === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $message = Release::signedMessage($version, (string) hash_file('sha256', $zipPath));

        return sodium_crypto_sign_verify_detached($sig, $message, $key);
    }

    /**
     * Sign the list of files that a release contains (release-files.json), so site health can
     * trust it when it compares the files on disk with the release.
     */
    public static function signFileList(string $version, string $listJson, string $secretKey): string
    {
        $secret = base64_decode($secretKey, true);

        if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new RuntimeException('The signing key is not a valid base64 Ed25519 secret key.');
        }

        return base64_encode(sodium_crypto_sign_detached(self::fileListMessage($version, $listJson), $secret));
    }

    public static function verifyFileList(string $version, string $listJson, string $signature, string $publicKey): bool
    {
        $key = base64_decode(trim($publicKey), true);
        $sig = base64_decode(trim($signature), true);

        if ($key === false || $sig === false || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::fileListMessage($version, $listJson), $key);
    }

    private static function fileListMessage(string $version, string $listJson): string
    {
        return 'nuvabill-files:'.$version.':'.hash('sha256', $listJson);
    }
}
