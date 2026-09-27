<?php

namespace App\Security\WebAuthn;

use App\Models\Admin;
use App\Models\Client;
use App\Models\Passkey;
use Illuminate\Http\Request;

/**
 * Passkeys (WebAuthn) without extra packages: makes the options for the browser, then checks what
 * the browser sends back. The site is the host of the current request, so passkeys work on any
 * domain Nuvabill runs on. Attestation is not checked; every sign-in needs the person's
 * fingerprint, face or device PIN (user verification), so a passkey counts as two factors.
 */
class Passkeys
{
    public const ES256 = -7;

    public const RS256 = -257;

    private const TIMEOUT_MS = 180000;

    private const PENDING_MINUTES = 5;

    private const TRANSPORTS = ['usb', 'nfc', 'ble', 'internal', 'hybrid', 'smart-card'];

    /**
     * Options for navigator.credentials.create(). Buffers are base64url encoded.
     *
     * @return array<string, mixed>
     */
    public function creationOptions(Request $request, Admin|Client $owner): array
    {
        $challenge = self::encode(random_bytes(32));

        $request->session()->put('passkeys.create', [
            'challenge' => $challenge,
            'owner' => $this->ownerKey($owner),
            'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->timestamp,
        ]);

        return [
            'challenge' => $challenge,
            'rp' => ['id' => $request->getHost(), 'name' => (string) setting('company.name')],
            'user' => ['id' => self::encode($this->userHandle($owner)), 'name' => $owner->email, 'displayName' => $owner->name],
            'pubKeyCredParams' => [['type' => 'public-key', 'alg' => self::ES256], ['type' => 'public-key', 'alg' => self::RS256]],
            'timeout' => self::TIMEOUT_MS,
            'attestation' => 'none',
            'authenticatorSelection' => ['residentKey' => 'required', 'requireResidentKey' => true, 'userVerification' => 'required'],
            'excludeCredentials' => $owner->passkeys()->get()->map(fn (Passkey $passkey): array => [
                'type' => 'public-key',
                'id' => $passkey->credential_id,
                'transports' => $passkey->transports ?? [],
            ])->values()->all(),
        ];
    }

    /**
     * Check the browser's answer to creationOptions() and save the new passkey.
     */
    public function register(Request $request, Admin|Client $owner, string $credentialJson, string $name): Passkey
    {
        $pending = $this->pullPending($request, 'passkeys.create');

        if (! hash_equals($pending['owner'] ?? '', $this->ownerKey($owner))) {
            throw new WebAuthnException('The passkey setup was started by someone else.');
        }

        $credential = $this->parseCredential($credentialJson);
        $clientData = self::decode($this->field($credential, 'response.clientDataJSON'));
        $this->checkClientData($request, $clientData, 'webauthn.create', $pending['challenge']);

        $attestation = Cbor::decode(self::decode($this->field($credential, 'response.attestationObject')));
        $authData = is_array($attestation) ? ($attestation['authData'] ?? null) : null;

        if (! is_string($authData)) {
            throw new WebAuthnException('The attestation has no authenticator data.');
        }

        $parsed = $this->parseAuthData($request, $authData, withCredential: true);
        $rawId = self::decode($this->field($credential, 'rawId'));

        if (! hash_equals($parsed['credential_id'], $rawId)) {
            throw new WebAuthnException('The credential ID does not match.');
        }

        [$publicKey, $algorithm] = $this->publicKeyPem($parsed['public_key']);
        $hash = hash('sha256', $rawId);

        if (Passkey::query()->where('credential_hash', $hash)->exists()) {
            throw new WebAuthnException('This passkey is already saved.');
        }

        $transports = array_values(array_intersect((array) ($credential['transports'] ?? []), self::TRANSPORTS));

        return $owner->passkeys()->create([
            'name' => $name,
            'credential_id' => self::encode($rawId),
            'credential_hash' => $hash,
            'public_key' => $publicKey,
            'algorithm' => $algorithm,
            'sign_count' => $parsed['sign_count'],
            'transports' => $transports ?: null,
        ]);
    }

    /**
     * Options for navigator.credentials.get(). No credentials are listed, so the browser offers
     * every passkey it has for this site and nobody learns which emails have one.
     *
     * @param  'admin'|'client'  $ownerType
     * @return array<string, mixed>
     */
    public function requestOptions(Request $request, string $ownerType): array
    {
        $challenge = self::encode(random_bytes(32));

        $request->session()->put("passkeys.get.{$ownerType}", [
            'challenge' => $challenge,
            'expires_at' => now()->addMinutes(self::PENDING_MINUTES)->timestamp,
        ]);

        return [
            'challenge' => $challenge,
            'rpId' => $request->getHost(),
            'timeout' => self::TIMEOUT_MS,
            'userVerification' => 'required',
            'allowCredentials' => [],
        ];
    }

    /**
     * Check a sign-in answer to requestOptions(). Returns the passkey that signed it.
     *
     * @param  'admin'|'client'  $ownerType
     */
    public function verify(Request $request, string $ownerType, string $credentialJson): Passkey
    {
        $pending = $this->pullPending($request, "passkeys.get.{$ownerType}");
        $credential = $this->parseCredential($credentialJson);
        $rawId = self::decode($this->field($credential, 'rawId'));

        $passkey = Passkey::query()
            ->where('credential_hash', hash('sha256', $rawId))
            ->where('owner_type', $ownerType)
            ->first();

        if ($passkey === null || $passkey->owner === null) {
            throw new WebAuthnException('This passkey is not saved on any account.');
        }

        $clientData = self::decode($this->field($credential, 'response.clientDataJSON'));
        $this->checkClientData($request, $clientData, 'webauthn.get', $pending['challenge']);

        $authData = self::decode($this->field($credential, 'response.authenticatorData'));
        $parsed = $this->parseAuthData($request, $authData, withCredential: false);
        $signature = self::decode($this->field($credential, 'response.signature'));

        if (openssl_verify($authData.hash('sha256', $clientData, true), $signature, $passkey->public_key, OPENSSL_ALGO_SHA256) !== 1) {
            throw new WebAuthnException('The signature is not valid.');
        }

        $userHandle = data_get($credential, 'response.userHandle');

        if (is_string($userHandle) && $userHandle !== '' && ! hash_equals($this->userHandle($passkey->owner), self::decode($userHandle))) {
            throw new WebAuthnException('The passkey belongs to another account.');
        }

        if (($parsed['sign_count'] > 0 || $passkey->sign_count > 0) && $parsed['sign_count'] <= $passkey->sign_count) {
            throw new WebAuthnException('The signature counter went back. The passkey may have been copied.');
        }

        $passkey->forceFill(['sign_count' => $parsed['sign_count'], 'last_used_at' => now()])->save();

        return $passkey;
    }

    public static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    public static function decode(string $value): string
    {
        $bytes = base64_decode(strtr($value, '-_', '+/'), true);

        if ($bytes === false || $value === '') {
            throw new WebAuthnException('A value is not valid base64url.');
        }

        return $bytes;
    }

    /**
     * The WebAuthn user handle: fixed for each person, and it does not reveal their ID or email.
     */
    private function userHandle(Admin|Client $owner): string
    {
        return hash_hmac('sha256', 'passkey-user:'.$this->ownerKey($owner), (string) config('app.key'), true);
    }

    private function ownerKey(Admin|Client $owner): string
    {
        return $owner->getMorphClass().':'.$owner->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function pullPending(Request $request, string $key): array
    {
        $pending = $request->session()->pull($key);

        if (! is_array($pending) || ! is_string($pending['challenge'] ?? null) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            throw new WebAuthnException('No passkey request is waiting, or it took too long.');
        }

        return $pending;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCredential(string $json): array
    {
        $credential = json_decode($json, true, 8);

        if (! is_array($credential) || ($credential['type'] ?? null) !== 'public-key') {
            throw new WebAuthnException('The passkey answer is not valid JSON.');
        }

        return $credential;
    }

    /**
     * @param  array<string, mixed>  $credential
     */
    private function field(array $credential, string $path): string
    {
        $value = data_get($credential, $path);

        if (! is_string($value) || $value === '') {
            throw new WebAuthnException("The passkey answer has no {$path}.");
        }

        return $value;
    }

    private function checkClientData(Request $request, string $json, string $type, string $challenge): void
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ($data['type'] ?? null) !== $type) {
            throw new WebAuthnException('The client data has the wrong type.');
        }

        if (! is_string($data['challenge'] ?? null) || ! hash_equals($challenge, $data['challenge'])) {
            throw new WebAuthnException('The challenge does not match.');
        }

        if (($data['origin'] ?? null) !== $request->getSchemeAndHttpHost() || ($data['crossOrigin'] ?? false) === true) {
            throw new WebAuthnException('The passkey was used on another site.');
        }
    }

    /**
     * Read the authenticator data: site hash, flags, counter and, when adding a passkey, its ID
     * and COSE public key.
     *
     * @return array{sign_count: int, credential_id?: string, public_key?: mixed}
     */
    private function parseAuthData(Request $request, string $authData, bool $withCredential): array
    {
        if (strlen($authData) < 37) {
            throw new WebAuthnException('The authenticator data is too short.');
        }

        if (! hash_equals(hash('sha256', $request->getHost(), true), substr($authData, 0, 32))) {
            throw new WebAuthnException('The passkey is for another site.');
        }

        $flags = ord($authData[32]);

        if (($flags & 0x01) === 0 || ($flags & 0x04) === 0) {
            throw new WebAuthnException('The person was not present or not verified.');
        }

        $parsed = ['sign_count' => unpack('N', substr($authData, 33, 4))[1]];

        if (! $withCredential) {
            return $parsed;
        }

        if (($flags & 0x40) === 0 || strlen($authData) < 55) {
            throw new WebAuthnException('The authenticator data has no new passkey.');
        }

        $length = unpack('n', substr($authData, 53, 2))[1];
        $credentialId = substr($authData, 55, $length);

        if ($length === 0 || strlen($credentialId) !== $length) {
            throw new WebAuthnException('The credential ID is cut short.');
        }

        [$publicKey] = Cbor::decodeAt($authData, 55 + $length);

        return $parsed + ['credential_id' => $credentialId, 'public_key' => $publicKey];
    }

    /**
     * Turn a COSE key (ES256 on P-256, or RS256) into a PEM public key for openssl_verify().
     *
     * @return array{0: string, 1: int}
     */
    private function publicKeyPem(mixed $cose): array
    {
        if (! is_array($cose)) {
            throw new WebAuthnException('The public key is not a COSE key.');
        }

        $algorithm = $cose[3] ?? null;
        $der = match (true) {
            $algorithm === self::ES256 && ($cose[1] ?? null) === 2 && ($cose[-1] ?? null) === 1
                && is_string($cose[-2] ?? null) && strlen($cose[-2]) === 32 && is_string($cose[-3] ?? null) && strlen($cose[-3]) === 32 => hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200')."\x04".$cose[-2].$cose[-3],
            $algorithm === self::RS256 && ($cose[1] ?? null) === 3 && is_string($cose[-1] ?? null) && is_string($cose[-2] ?? null) => self::der(0x30, self::der(0x30, hex2bin('06092a864886f70d0101010500')).self::der(0x03, "\x00".self::der(0x30, self::derInteger($cose[-1]).self::derInteger($cose[-2])))),
            default => throw new WebAuthnException('This kind of passkey is not supported.'),
        };

        $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END PUBLIC KEY-----\n";

        if (openssl_pkey_get_public($pem) === false) {
            throw new WebAuthnException('The public key is not valid.');
        }

        return [$pem, $algorithm];
    }

    private static function der(int $tag, string $content): string
    {
        $length = strlen($content);
        $lengthBytes = $length < 0x80 ? chr($length) : chr(0x80 | strlen(ltrim(pack('N', $length), "\x00"))).ltrim(pack('N', $length), "\x00");

        return chr($tag).$lengthBytes.$content;
    }

    private static function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");

        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return self::der(0x02, $bytes);
    }
}
