<?php

namespace App\Import\Whmcs;

/**
 * WHMCS's own reversible encryption for server passwords, service passwords and (in very old
 * versions) client passwords, keyed with the "cc_encryption_hash" from its configuration.php.
 */
final class WhmcsCrypt
{
    public function __construct(private readonly string $key) {}

    /**
     * The plain text, or null when the value cannot be read with this key.
     */
    public function decrypt(?string $value): ?string
    {
        $data = base64_decode((string) $value, true);
        $hashKey = self::hash(md5(md5($this->key)).md5($this->key));
        $length = strlen($hashKey);

        if ($data === false || strlen($data) <= $length) {
            return null;
        }

        $key = '';

        for ($c = 0; $c < $length; $c++) {
            $key .= chr(ord($data[$c]) ^ ord($hashKey[$c]));
        }

        $data = substr($data, $length);
        $out = '';

        for ($c = 0, $size = strlen($data); $c < $size; $c++) {
            if ($c !== 0 && $c % $length === 0) {
                $key = self::hash($key.substr($out, $c - $length, $length));
            }

            $out .= chr(ord($key[$c % $length]) ^ ord($data[$c]));
        }

        // A wrong key gives random bytes; a real password is printable text.
        return $out !== '' && mb_check_encoding($out, 'UTF-8') && ! preg_match('/[\x00-\x1F\x7F]/', $out) ? $out : null;
    }

    /**
     * The same encryption WHMCS uses. Only needed by tests, which make WHMCS-style data.
     */
    public function encrypt(string $value): string
    {
        $hashKey = self::hash(md5(md5($this->key)).md5($this->key));
        $length = strlen($hashKey);
        $iv = random_bytes($length);
        $out = '';

        for ($c = 0; $c < $length; $c++) {
            $out .= chr(ord($iv[$c]) ^ ord($hashKey[$c]));
        }

        $key = $iv;

        for ($c = 0, $size = strlen($value); $c < $size; $c++) {
            if ($c !== 0 && $c % $length === 0) {
                $key = self::hash($key.substr($value, $c - $length, $length));
            }

            $out .= chr(ord($key[$c % $length]) ^ ord($value[$c]));
        }

        return base64_encode($out);
    }

    private static function hash(string $value): string
    {
        return (string) hex2bin(sha1($value));
    }
}
