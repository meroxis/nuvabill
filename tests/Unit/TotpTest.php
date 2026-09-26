<?php

namespace Tests\Unit;

use App\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    /**
     * RFC 6238 appendix B test vectors (SHA-1), last six digits.
     *
     * @return array<string, array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            't=59' => [59, '287082'],
            't=1111111109' => [1111111109, '081804'],
            't=1111111111' => [1111111111, '050471'],
            't=1234567890' => [1234567890, '005924'],
            't=2000000000' => [2000000000, '279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_codes_match_the_rfc_test_vectors(int $timestamp, string $expected): void
    {
        $secret = Totp::base32Encode('12345678901234567890');

        $this->assertSame($expected, Totp::codeAt($secret, intdiv($timestamp, 30)));
        $this->assertTrue(Totp::verify($secret, $expected, $timestamp));
    }

    public function test_codes_from_the_previous_or_next_step_are_accepted_but_older_ones_are_not(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;

        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, intdiv($now, 30) - 1), $now));
        $this->assertTrue(Totp::verify($secret, Totp::codeAt($secret, intdiv($now, 30) + 1), $now));
        $this->assertFalse(Totp::verify($secret, Totp::codeAt($secret, intdiv($now, 30) - 3), $now));
    }

    public function test_malformed_codes_are_rejected(): void
    {
        $secret = Totp::generateSecret();

        $this->assertFalse(Totp::verify($secret, ''));
        $this->assertFalse(Totp::verify($secret, 'abcdef'));
        $this->assertFalse(Totp::verify($secret, '1234567'));
    }

    public function test_base32_round_trips(): void
    {
        $bytes = random_bytes(20);

        $this->assertSame($bytes, Totp::base32Decode(Totp::base32Encode($bytes)));
    }

    public function test_provisioning_uri_contains_issuer_and_secret(): void
    {
        $uri = Totp::provisioningUri('JBSWY3DPEHPK3PXP', 'staff@example.com', 'My Host');

        $this->assertStringStartsWith('otpauth://totp/My%20Host:staff%40example.com?', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=My%20Host', $uri);
    }
}
