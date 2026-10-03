<?php

namespace Tests\Feature\Auth;

use App\Enums\ClientStatus;
use App\Mail\TemplatedMessage;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Passkey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

class PasskeyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_add_a_passkey_and_sign_in_with_it_without_the_two_factor_step(): void
    {
        $admin = Admin::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        $key = $this->ecKey();

        $this->actingAs($admin, 'admin')->postJson(route('admin.profile.passkeys.options'), ['current_password' => 'wrong'])->assertUnprocessable();
        $options = $this->postJson(route('admin.profile.passkeys.options'), ['current_password' => 'password'])
            ->assertOk()
            ->assertJsonPath('rp.id', $this->host())
            ->assertJsonPath('authenticatorSelection.userVerification', 'required')
            ->json();

        $this->post(route('admin.profile.passkeys.store'), ['name' => 'My laptop', 'credential' => $this->registration($options, 'cred-staff', $key)])
            ->assertSessionHas('status');
        $passkey = $admin->passkeys()->sole();
        $this->assertSame('My laptop', $passkey->name);
        $this->get(route('admin.profile.edit'))->assertSee('My laptop');

        Auth::guard('admin')->logout();

        $options = $this->postJson(route('admin.passkey.options'))->assertOk()->assertJsonPath('allowCredentials', [])->json();
        $this->post(route('admin.passkey.login'), ['credential' => $this->assertion($options, 'cred-staff', $key, 1), 'remember' => '1'])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame(1, $passkey->fresh()->sign_count);
        $this->assertNotNull($passkey->fresh()->last_used_at);
    }

    public function test_a_passkey_answer_is_refused_when_anything_is_off(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $admin = Admin::factory()->create();
        $key = $this->ecKey();
        $this->addPasskey($admin, 'cred-staff', $key, signCount: 5);
        $options = fn (): array => $this->postJson(route('admin.passkey.options'))->json();

        $cases = [
            'no waiting request' => fn (): string => $this->assertion(['challenge' => 'abc'], 'cred-staff', $key, 6),
            'another site' => fn (): string => $this->assertion($options(), 'cred-staff', $key, 6, rpId: 'evil.test'),
            'another origin' => fn (): string => $this->assertion($options(), 'cred-staff', $key, 6, origin: 'https://evil.test'),
            'wrong key' => fn (): string => $this->assertion($options(), 'cred-staff', $this->ecKey(), 6),
            'counter went back' => fn (): string => $this->assertion($options(), 'cred-staff', $key, 5),
            'person not verified' => fn (): string => $this->assertion($options(), 'cred-staff', $key, 6, flags: 0x01),
            'unknown passkey' => fn (): string => $this->assertion($options(), 'cred-other', $key, 6),
        ];

        foreach ($cases as $case => $credential) {
            $this->post(route('admin.passkey.login'), ['credential' => $credential()])->assertSessionHasErrors('email');
            $this->assertGuest('admin');
            $this->assertNull(Passkey::query()->sole()->last_used_at, $case);
        }

        $challenge = $options();
        $credential = $this->assertion($challenge, 'cred-staff', $key, 6);
        $this->post(route('admin.passkey.login'), ['credential' => $credential])->assertRedirect(route('admin.dashboard'));

        Auth::guard('admin')->logout();
        $this->post(route('admin.passkey.login'), ['credential' => $credential])->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_clients_sign_in_with_an_rsa_passkey_and_only_manage_their_own(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['has_password' => false]);
        $other = Client::factory()->create();
        $otherPasskey = $this->addPasskey($other, 'cred-other', $this->ecKey());
        $key = $this->rsaKey();

        $this->actingAs($client, 'web');
        $options = $this->postJson(route('client.account.passkeys.options'), ['passkey_email_code' => $this->emailedCode()])->assertOk()->json();
        $this->post(route('client.account.passkeys.store'), ['name' => '', 'credential' => $this->registration($options, 'cred-client', $key)])
            ->assertSessionHas('status');
        $this->assertSame(-257, $client->passkeys()->sole()->algorithm);
        $this->delete(route('client.account.passkeys.destroy', $otherPasskey))->assertNotFound();

        Auth::guard('web')->logout();
        $options = $this->postJson(route('client.passkey.options'))->json();
        $this->post(route('client.passkey.login'), ['credential' => $this->assertion($options, 'cred-client', $key, 0)])
            ->assertRedirect(route('client.dashboard'));
        $this->assertAuthenticatedAs($client, 'web');

        Auth::guard('web')->logout();
        $client->update(['status' => ClientStatus::Closed]);
        $options = $this->postJson(route('client.passkey.options'))->json();
        $this->post(route('client.passkey.login'), ['credential' => $this->assertion($options, 'cred-client', $key, 0)])
            ->assertSessionHasErrors('email');
        $this->assertGuest('web');

        $options = $this->postJson(route('admin.passkey.options'))->json();
        $this->post(route('admin.passkey.login'), ['credential' => $this->assertion($options, 'cred-client', $key, 0)])
            ->assertSessionHasErrors('email');
        $this->assertGuest('admin');
    }

    public function test_clients_without_a_password_need_an_emailed_code_to_add_a_passkey(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['has_password' => false]);
        $this->actingAs($client, 'web');

        // A stolen session alone cannot add a lasting way back in.
        $this->get(route('client.account.edit'))->assertOk()->assertSee('name="passkey_email_code"', false)->assertDontSee('name="passkey_current_password"', false);
        $this->postJson(route('client.account.passkeys.options'))
            ->assertUnprocessable()
            ->assertJsonPath('message', 'That code is not right, or it is too old. Ask for a new code.');
        $this->postJson(route('client.account.passkeys.options'), ['passkey_email_code' => '000000'])->assertUnprocessable();
        $this->assertNull(session('passkeys.create'));

        // The code works once.
        $code = $this->emailedCode();
        $this->postJson(route('client.account.passkeys.options'), ['passkey_email_code' => $code])->assertOk()->assertJsonPath('rp.id', $this->host());
        $this->postJson(route('client.account.passkeys.options'), ['passkey_email_code' => $code])->assertUnprocessable();
    }

    public function test_clients_with_a_password_confirm_it_to_add_a_passkey(): void
    {
        $client = Client::factory()->create(['password' => 'right-password-1']);
        $this->actingAs($client, 'web');

        $this->get(route('client.account.edit'))->assertOk()->assertSee('name="passkey_current_password"', false)->assertDontSee('name="passkey_email_code"', false);
        $this->postJson(route('client.account.passkeys.options'), ['passkey_current_password' => 'wrong-password'])->assertUnprocessable()->assertJsonValidationErrors('passkey_current_password');
        $this->postJson(route('client.account.passkeys.options'), ['passkey_current_password' => 'right-password-1'])->assertOk()->assertJsonPath('rp.id', $this->host());
    }

    public function test_the_sign_in_pages_offer_passkeys(): void
    {
        $this->get(route('admin.login'))->assertOk()->assertSee('Sign in with a passkey');
        $this->get(route('client.login'))->assertOk()->assertSee('Sign in with a passkey');
    }

    /**
     * Ask for the code that confirms an account change, and read it from the email.
     */
    private function emailedCode(): string
    {
        $this->post(route('client.account.email-code'))->assertSessionHas('status');
        preg_match('/\b(\d{6})\b/', Mail::sent(TemplatedMessage::class)->last()->bodyHtml, $match);

        return $match[1];
    }

    private function addPasskey(Admin|Client $owner, string $credentialId, OpenSSLAsymmetricKey $key, int $signCount = 0): Passkey
    {
        return $owner->passkeys()->create([
            'name' => 'Phone',
            'credential_id' => rtrim(strtr(base64_encode($credentialId), '+/', '-_'), '='),
            'credential_hash' => hash('sha256', $credentialId),
            'public_key' => openssl_pkey_get_details($key)['key'],
            'algorithm' => -7,
            'sign_count' => $signCount,
        ]);
    }

    /**
     * What a browser sends after navigator.credentials.create() with attestation "none".
     *
     * @param  array<string, mixed>  $options
     */
    private function registration(array $options, string $credentialId, OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        $cose = isset($details['ec'])
            ? $this->cborMap([[$this->cborInt(1), $this->cborInt(2)], [$this->cborInt(3), $this->cborInt(-7)], [$this->cborInt(-1), $this->cborInt(1)],
                [$this->cborInt(-2), $this->cborBytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT))],
                [$this->cborInt(-3), $this->cborBytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT))]])
            : $this->cborMap([[$this->cborInt(1), $this->cborInt(3)], [$this->cborInt(3), $this->cborInt(-257)],
                [$this->cborInt(-1), $this->cborBytes($details['rsa']['n'])], [$this->cborInt(-2), $this->cborBytes($details['rsa']['e'])]]);

        $authData = hash('sha256', $this->host(), true).chr(0x45).pack('N', 0).str_repeat("\0", 16)
            .pack('n', strlen($credentialId)).$credentialId.$cose;
        $attestation = $this->cborMap([
            [$this->cborText('fmt'), $this->cborText('none')],
            [$this->cborText('attStmt'), $this->cborMap([])],
            [$this->cborText('authData'), $this->cborBytes($authData)],
        ]);

        return json_encode([
            'id' => $this->b64($credentialId),
            'rawId' => $this->b64($credentialId),
            'type' => 'public-key',
            'transports' => ['internal', 'made-up'],
            'response' => [
                'clientDataJSON' => $this->b64(json_encode(['type' => 'webauthn.create', 'challenge' => $options['challenge'], 'origin' => $this->origin()])),
                'attestationObject' => $this->b64($attestation),
            ],
        ]);
    }

    /**
     * What a browser sends after navigator.credentials.get().
     *
     * @param  array<string, mixed>  $options
     */
    private function assertion(array $options, string $credentialId, OpenSSLAsymmetricKey $key, int $signCount, ?string $rpId = null, ?string $origin = null, int $flags = 0x05): string
    {
        $clientData = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin ?? $this->origin()]);
        $authData = hash('sha256', $rpId ?? $this->host(), true).chr($flags).pack('N', $signCount);
        openssl_sign($authData.hash('sha256', $clientData, true), $signature, $key, OPENSSL_ALGO_SHA256);

        return json_encode([
            'id' => $this->b64($credentialId),
            'rawId' => $this->b64($credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->b64($clientData),
                'authenticatorData' => $this->b64($authData),
                'signature' => $this->b64($signature),
                'userHandle' => null,
            ],
        ]);
    }

    private function ecKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC] + $this->opensslConfig());
    }

    private function rsaKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048] + $this->opensslConfig());
    }

    /**
     * Windows PHP builds cannot make keys without an openssl.cnf, so give them a tiny one there.
     * Other systems use their own.
     *
     * @return array{config?: string}
     */
    private function opensslConfig(): array
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            return [];
        }

        $path = sys_get_temp_dir().'/nuvabill-test-openssl-v2.cnf';

        if (! is_file($path)) {
            file_put_contents($path, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n");
        }

        return ['config' => $path];
    }

    private function host(): string
    {
        return (string) parse_url(url('/'), PHP_URL_HOST);
    }

    private function origin(): string
    {
        return rtrim(url('/'), '/');
    }

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function cborHead(int $major, int $length): string
    {
        return match (true) {
            $length < 24 => chr(($major << 5) | $length),
            $length < 256 => chr(($major << 5) | 24).chr($length),
            default => chr(($major << 5) | 25).pack('n', $length),
        };
    }

    private function cborInt(int $value): string
    {
        return $value >= 0 ? $this->cborHead(0, $value) : $this->cborHead(1, -1 - $value);
    }

    private function cborBytes(string $bytes): string
    {
        return $this->cborHead(2, strlen($bytes)).$bytes;
    }

    private function cborText(string $text): string
    {
        return $this->cborHead(3, strlen($text)).$text;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $pairs  encoded keys and values
     */
    private function cborMap(array $pairs): string
    {
        return $this->cborHead(5, count($pairs)).implode('', array_map(fn (array $pair): string => $pair[0].$pair[1], $pairs));
    }
}
