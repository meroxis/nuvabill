<?php

namespace App\Auth;

/**
 * Password hashes brought over by an import, which Nuvabill checks once at the client's first sign-in
 * and then replaces with its own hash. The stored form says which kind it is:
 *
 * - "md5-salt:{hash}:{salt}"                 WHMCS 4.2 to 6.2: md5(salt . password)
 * - "hmac-bcrypt:{source}:{bcrypt}"          Blesta: bcrypt of HMAC-SHA256(password, the source's key)
 * - "pbkdf2:{algo}:{iterations}:{salt}:{hash}" ClientExec: PBKDF2, hash in base64
 * - "native:{hash}"                          any other PHP password_hash() result, for example Argon2
 *
 * Keys for "hmac-bcrypt" are kept, encrypted, in the "import.password_keys" setting.
 */
final class LegacyPassword
{
    public static function isBcrypt(?string $hash): bool
    {
        return (bool) preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', (string) $hash);
    }

    /**
     * "$2a$" and "$2b$" hashes are the same algorithm; Laravel checks the "$2y$" form.
     */
    public static function normalizeBcrypt(string $hash): string
    {
        return '$2y$'.substr($hash, 4);
    }

    public static function check(string $stored, string $password): bool
    {
        $parts = explode(':', $stored);

        return match ($parts[0]) {
            'md5-salt' => count($parts) >= 3 && hash_equals(strtolower($parts[1]), md5(implode(':', array_slice($parts, 2)).$password)),
            'hmac-bcrypt' => count($parts) === 3 && filled($key = self::key($parts[1])) && password_verify(hash_hmac('sha256', $password, (string) $key), $parts[2]),
            'pbkdf2' => count($parts) === 5 && self::pbkdf2($parts[1], (int) $parts[2], $parts[3], $parts[4], $password),
            'native' => count($parts) >= 2 && password_verify($password, substr($stored, 7)),
            default => false,
        };
    }

    private static function pbkdf2(string $algo, int $iterations, string $salt, string $hash, string $password): bool
    {
        $expected = base64_decode($hash, true);

        if ($expected === false || $expected === '' || $iterations < 1 || $iterations > 1_000_000 || ! in_array($algo, hash_hmac_algos(), true)) {
            return false;
        }

        return hash_equals($expected, hash_pbkdf2($algo, $password, $salt, $iterations, strlen($expected), true));
    }

    private static function key(string $source): ?string
    {
        $keys = (array) setting('import.password_keys', []);

        return isset($keys[$source]) ? (string) $keys[$source] : null;
    }
}
