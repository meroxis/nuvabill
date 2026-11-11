<?php

namespace App\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Password hashes brought over by an import, which Nuvabill checks once at the client's first sign-in
 * and then replaces with its own hash. The stored form says which kind it is:
 *
 * - "md5-salt:{hash}:{salt}"                 WHMCS 4.2 to 6.2: md5(salt . password), as the import reads it
 * - "md5-salt-wrapped:{slow hash}:{salt}"    the same password, kept as Nuvabill's own slow hash of that MD5
 * - "hmac-bcrypt:{source}:{bcrypt}"          Blesta: bcrypt of HMAC-SHA256(password, the source's key)
 * - "pbkdf2:{algo}:{iterations}:{salt}:{hash}" ClientExec: PBKDF2, hash in base64
 * - "native:{hash}"                          any other PHP password_hash() result, for example Argon2
 *
 * Keys for "hmac-bcrypt" are kept, encrypted, in the "import.password_keys" setting.
 *
 * MD5 is fast to guess, so the "md5-salt" form only stays for the few minutes until the scheduler
 * has wrapped it ({@see wrapStored()}). The other kinds are slow hashes already.
 */
final class LegacyPassword
{
    private const FAST = 'md5-salt:';

    private const WRAPPED = 'md5-salt-wrapped:';

    /**
     * Kept in the cache for an hour after a pass found no fast hash left, so the clients table is not
     * read every minute. An import takes it away, and without one the table is read again each hour.
     */
    private const NONE_LEFT = 'nuvabill:legacy-passwords:none-left';

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
            'md5-salt-wrapped' => count($parts) >= 3 && password_verify(md5(implode(':', array_slice($parts, 2)).$password), $parts[1]),
            'hmac-bcrypt' => count($parts) === 3 && filled($key = self::key($parts[1])) && password_verify(hash_hmac('sha256', $password, (string) $key), $parts[2]),
            'pbkdf2' => count($parts) === 5 && self::pbkdf2($parts[1], (int) $parts[2], $parts[3], $parts[4], $password),
            'native' => count($parts) >= 2 && password_verify($password, substr($stored, 7)),
            default => false,
        };
    }

    public static function isFast(string $stored): bool
    {
        return str_starts_with($stored, self::FAST);
    }

    /**
     * The stored form with a fast hash put inside Nuvabill's own slow password hash, which checks the
     * same password. Kinds that are slow already come back as they are. Null when the fast form is
     * broken and could never be checked.
     */
    public static function wrap(string $stored): ?string
    {
        if (! self::isFast($stored)) {
            return $stored;
        }

        $parts = explode(':', $stored);

        if (count($parts) < 3 || ! preg_match('/^[a-f0-9]{32}$/i', $parts[1])) {
            return null;
        }

        return self::WRAPPED.Hash::make(strtolower($parts[1])).':'.implode(':', array_slice($parts, 2));
    }

    /**
     * Whether the scheduler should look for fast hashes now.
     */
    public static function mayHaveFastHashes(): bool
    {
        return ! Cache::has(self::NONE_LEFT);
    }

    /**
     * Called when an import saved fast hashes, so the scheduler wraps them at its next minute.
     */
    public static function wrapSoon(): void
    {
        Cache::forget(self::NONE_LEFT);
    }

    /**
     * Wrap the fast hashes that imports left on clients, for as long as the time allows: a slow hash
     * takes a moment for each client, so a large import is finished over several runs. Returns how
     * many clients were done.
     */
    public static function wrapStored(int $seconds = 20): int
    {
        $deadline = microtime(true) + $seconds;
        $done = 0;
        $afterId = 0;

        do {
            $rows = DB::table('clients')->where('id', '>', $afterId)->where('legacy_password', 'like', self::FAST.'%')->orderBy('id')->limit(50)->get(['id', 'legacy_password']);

            foreach ($rows as $row) {
                if (microtime(true) >= $deadline) {
                    return $done;
                }

                $afterId = (int) $row->id;

                // Only while the hash is still the one read: the client may sign in at this moment,
                // which replaces it with a Nuvabill password.
                $done += DB::table('clients')->where('id', $row->id)->where('legacy_password', $row->legacy_password)
                    ->update(['legacy_password' => self::wrap((string) $row->legacy_password)]);
            }
        } while ($rows->count() === 50);

        // An import may have saved more while this pass ran; then the next minute carries on. The mark
        // is set before the last look, so an import that saves right after that look (and then calls
        // wrapSoon()) always takes it away again.
        Cache::put(self::NONE_LEFT, true, now()->addHour());

        if (DB::table('clients')->where('legacy_password', 'like', self::FAST.'%')->exists()) {
            Cache::forget(self::NONE_LEFT);
        }

        return $done;
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
