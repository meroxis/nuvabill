<?php

namespace Tests\Unit;

use App\Push\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebPushTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_message_decrypts_on_the_receiving_browser(): void
    {
        // The browser's side: its own key pair and auth secret, as pushManager.subscribe() makes them.
        $options = ['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC];
        $browserKey = openssl_pkey_new($options) ?: openssl_pkey_new($options + ['config' => resource_path('openssl.cnf')]);
        $browserPublic = WebPush::rawPublicKey($browserKey);
        $auth = random_bytes(16);

        $body = app(WebPush::class)->encrypt('{"title":"New order 1001"}', $browserPublic, $auth);

        $this->assertSame('{"title":"New order 1001"}', $this->decrypt($body, $browserKey, $browserPublic, $auth));
    }

    public function test_the_token_is_signed_with_the_sites_public_key(): void
    {
        $push = app(WebPush::class);
        $token = $push->token('https://fcm.googleapis.com/fcm/send/abc');
        [$header, $claims, $signature] = explode('.', $token);

        $this->assertSame(['typ' => 'JWT', 'alg' => 'ES256'], json_decode(WebPush::decode($header), true));
        $this->assertSame('https://fcm.googleapis.com', json_decode(WebPush::decode($claims), true)['aud']);
        $this->assertSame(64, strlen(WebPush::decode($signature)));

        $raw = WebPush::decode($signature);
        $der = $this->der(substr($raw, 0, 32), substr($raw, 32));
        $this->assertSame(1, openssl_verify($header.'.'.$claims, $der, WebPush::publicKeyFromRaw(WebPush::decode($push->publicKey())), OPENSSL_ALGO_SHA256));
        // The key pair is kept, not made again.
        $this->assertSame($push->publicKey(), app(WebPush::class)->publicKey());
    }

    public function test_only_real_push_services_are_accepted(): void
    {
        $this->assertTrue(WebPush::isPushEndpoint('https://fcm.googleapis.com/fcm/send/abc'));
        $this->assertTrue(WebPush::isPushEndpoint('https://web.push.apple.com/QGx'));
        $this->assertTrue(WebPush::isPushEndpoint('https://updates.push.services.mozilla.com/wpush/v2/x'));
        $this->assertTrue(WebPush::isPushEndpoint('https://wns2-par02p.notify.windows.com/w/?token=x'));
        $this->assertFalse(WebPush::isPushEndpoint('http://fcm.googleapis.com/fcm/send/abc'));
        $this->assertFalse(WebPush::isPushEndpoint('https://127.0.0.1/admin'));
        $this->assertFalse(WebPush::isPushEndpoint('https://evil.example/push.apple.com'));
        $this->assertFalse(WebPush::isPushEndpoint('https://push.apple.com.evil.example/x'));
    }

    /**
     * What the browser does with the body (RFC 8291), written separately from Nuvabill's code.
     */
    private function decrypt(string $body, \OpenSSLAsymmetricKey $browserKey, string $browserPublic, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $this->assertSame(4096, unpack('N', substr($body, 16, 4))[1]);
        $keyLength = ord($body[20]);
        $serverPublic = substr($body, 21, $keyLength);
        $encrypted = substr($body, 21 + $keyLength);

        $shared = openssl_pkey_derive(WebPush::publicKeyFromRaw($serverPublic), $browserKey);
        $prkKey = hash_hmac('sha256', $shared, $auth, true);
        $ikm = hash_hmac('sha256', "WebPush: info\0".$browserPublic.$serverPublic."\x01", $prkKey, true);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\0\x01", $prk, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\0\x01", $prk, true), 0, 12);

        $plain = openssl_decrypt(substr($encrypted, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($encrypted, -16));
        $this->assertIsString($plain);
        $this->assertSame("\x02", substr($plain, -1));

        return substr($plain, 0, -1);
    }

    private function der(string $r, string $s): string
    {
        $integer = function (string $value): string {
            $value = ltrim($value, "\0");

            if (ord($value[0]) > 0x7F) {
                $value = "\0".$value;
            }

            return "\x02".chr(strlen($value)).$value;
        };
        $sequence = $integer($r).$integer($s);

        return "\x30".chr(strlen($sequence)).$sequence;
    }
}
