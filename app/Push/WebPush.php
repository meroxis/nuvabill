<?php

namespace App\Push;

use App\Models\PushSubscription;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * Web Push without a library: the message is encrypted for the one browser that asked for it
 * (RFC 8291, "aes128gcm") and signed with this site's own key (VAPID, RFC 8292), so the phone
 * maker's push service can deliver it but never read it.
 */
class WebPush
{
    /**
     * DER prefix of a P-256 public key; the 65-byte uncompressed point follows it.
     */
    private const P256_PUBLIC_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    /**
     * The push services of Chrome, Firefox, Safari and Edge. Nuvabill posts to nothing else, so a
     * staff account cannot make the server call an address of its choice.
     *
     * @var list<string>
     */
    private const PUSH_HOSTS = ['fcm.googleapis.com', 'android.googleapis.com', '.push.services.mozilla.com', '.push.apple.com', '.notify.windows.com'];

    public function __construct(private Settings $settings) {}

    /**
     * This site's public key, for the browser's applicationServerKey. The key pair is made once.
     */
    public function publicKey(): string
    {
        return $this->keys()['public'];
    }

    public static function isPushEndpoint(string $endpoint): bool
    {
        $parts = parse_url($endpoint);

        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || ! isset($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (self::PUSH_HOSTS as $allowed) {
            if (str_starts_with($allowed, '.') ? str_ends_with($host, $allowed) : $host === $allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sends one alert to one phone or browser. A subscription the push service no longer knows
     * (the app was removed or alerts were turned off) is deleted.
     *
     * @param  array{title: string, body?: string, url?: string, tag?: string}  $message
     */
    public function send(PushSubscription $subscription, array $message): bool
    {
        if (! self::isPushEndpoint($subscription->endpoint)) {
            $subscription->delete();

            return false;
        }

        $body = $this->encrypt((string) json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), self::decode($subscription->public_key), self::decode($subscription->auth_token));

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Authorization' => 'vapid t='.$this->token($subscription->endpoint).', k='.$this->publicKey(),
                    'Content-Encoding' => 'aes128gcm',
                    'TTL' => '86400',
                    'Urgency' => 'high',
                ])
                ->withBody($body, 'application/octet-stream')
                ->post($subscription->endpoint);
        } catch (ConnectionException) {
            return false;
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();

            return false;
        }

        if ($response->successful()) {
            $subscription->forceFill(['last_sent_at' => now()])->save();

            return true;
        }

        return false;
    }

    /**
     * The aes128gcm body: salt, record size, this message's own public key, then the encrypted text.
     */
    public function encrypt(string $payload, string $userPublicKey, string $authSecret): string
    {
        if (strlen($userPublicKey) !== 65 || strlen($authSecret) !== 16) {
            throw new RuntimeException('This browser sent a push key Nuvabill cannot use.');
        }

        $local = self::newKey();
        $localPublic = self::rawPublicKey($local);
        $shared = openssl_pkey_derive(self::publicKeyFromRaw($userPublicKey), $local);

        if ($shared === false) {
            throw new RuntimeException('The push message could not be encrypted.');
        }

        $keyMaterial = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$userPublicKey.$localPublic, $authSecret);
        $salt = random_bytes(16);
        $contentKey = hash_hkdf('sha256', $keyMaterial, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $keyMaterial, 12, "Content-Encoding: nonce\0", $salt);

        // "\x02" marks the last (and only) record.
        $tag = '';
        $cipherText = openssl_encrypt($payload."\x02", 'aes-128-gcm', $contentKey, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', 4096).chr(65).$localPublic.$cipherText.$tag;
    }

    /**
     * The signed token that proves the alert comes from this site (ES256 JWT, valid 12 hours).
     */
    public function token(string $endpoint): string
    {
        $parts = (array) parse_url($endpoint);
        $contact = (string) setting('company.email');
        $claims = [
            'aud' => ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? ''),
            'exp' => time() + 12 * 3600,
            'sub' => $contact !== '' ? 'mailto:'.$contact : url('/'),
        ];
        $input = self::encode((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'.self::encode((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($this->keys()['private']);

        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The push key of this site cannot sign.');
        }

        return $input.'.'.self::encode(self::rawSignature($signature));
    }

    /**
     * @return array{public: string, private: string}
     */
    private function keys(): array
    {
        $public = (string) setting('push.vapid_public');
        $private = (string) setting('push.vapid_private');

        if ($public !== '' && $private !== '') {
            return ['public' => $public, 'private' => $private];
        }

        // Two first requests at the same time must not make two different keys.
        return Cache::lock('nuvabill:push-keys', 10)->block(10, function (): array {
            $this->settings->flush();

            if (setting('push.vapid_public') !== '' && setting('push.vapid_private') !== '') {
                return ['public' => (string) setting('push.vapid_public'), 'private' => (string) setting('push.vapid_private')];
            }

            $key = self::newKey();
            openssl_pkey_export($key, $pem, null, self::options()) || openssl_pkey_export($key, $pem, null, self::options(true));
            $keys = ['public' => self::encode(self::rawPublicKey($key)), 'private' => (string) $pem];
            $this->settings->setMany(['push.vapid_public' => $keys['public'], 'push.vapid_private' => $keys['private']]);

            return $keys;
        });
    }

    private static function newKey(): OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(self::options() + ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC])
            ?: openssl_pkey_new(self::options(true) + ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);

        if ($key === false) {
            throw new RuntimeException('This server cannot make P-256 keys (OpenSSL).');
        }

        return $key;
    }

    /**
     * PHP on Windows often cannot find OpenSSL's settings file, and then cannot make keys; Nuvabill
     * brings a small one for that case.
     *
     * @return array<string, string>
     */
    private static function options(bool $ownConfig = false): array
    {
        return $ownConfig ? ['config' => resource_path('openssl.cnf')] : [];
    }

    /**
     * The 65-byte uncompressed point: 0x04, then X and Y.
     */
    public static function rawPublicKey(OpenSSLAsymmetricKey $key): string
    {
        $details = (array) openssl_pkey_get_details($key);

        return "\x04".str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function publicKeyFromRaw(string $raw): OpenSSLAsymmetricKey
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode((string) hex2bin(self::P256_PUBLIC_PREFIX).$raw), 64, "\n")."-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new RuntimeException('This browser sent a push key Nuvabill cannot use.');
        }

        return $key;
    }

    /**
     * OpenSSL signs in DER (two integers); a JWT wants the two 32-byte numbers side by side.
     */
    private static function rawSignature(string $der): string
    {
        $offset = 2;
        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $parts[] = str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0].$parts[1];
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);
    }
}
