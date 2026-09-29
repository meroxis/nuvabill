<?php

namespace App\Ai;

use Closure;

/**
 * Takes private details out of text before it goes to the AI: email addresses, phone numbers,
 * card and bank account numbers, passwords written after a label, private keys and long secret
 * keys. Dates, IP addresses, ticket and invoice numbers stay, because answers need them.
 */
final class Redactor
{
    /**
     * Words people put before a password, in the languages Nuvabill speaks most.
     */
    private const PASSWORD_WORDS = 'password|passwd|pass|pwd|passwort|mot de passe|contraseña|senha|parola|wachtwoord|lösenord|şifre|пароль|كلمة المرور|كلمة السر|סיסמה|وشەی نهێنی|密码';

    /**
     * Replace private details with markers like [email] and [phone].
     */
    public static function clean(string $text): string
    {
        return self::apply($text, fn (string $kind, string $original): string => '['.$kind.']');
    }

    /**
     * Replace private details with numbered markers like [email 1], and return what each marker
     * stood for, so restore() can put the details back into the AI's answer.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function mask(string $text): array
    {
        $found = [];
        $masked = self::apply($text, function (string $kind, string $original) use (&$found): string {
            $marker = '['.$kind.' '.(count($found) + 1).']';
            $found[$marker] = $original;

            return $marker;
        });

        return [$masked, $found];
    }

    /**
     * Put masked details back. Returns null when the answer lost one of the markers.
     *
     * @param  array<string, string>  $found
     */
    public static function restore(string $text, array $found): ?string
    {
        foreach (array_keys($found) as $marker) {
            if (! str_contains($text, $marker)) {
                return null;
            }
        }

        return strtr($text, $found);
    }

    /**
     * @param  Closure(string, string): string  $marker
     */
    private static function apply(string $text, Closure $marker): string
    {
        $replace = fn (string $kind): Closure => fn (array $match): string => $marker($kind, $match[0]);

        $text = (string) preg_replace_callback('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s', $replace('private key'), $text);
        $text = (string) preg_replace_callback('/(?<![\w.+-])[\w.+-]+@[\w-]+(?:\.[\w-]+)+/u', $replace('email'), $text);
        $text = (string) preg_replace_callback('/('.self::PASSWORD_WORDS.')(\s*[:=]\s*|\s+(?:is|es|ist|est|é)\s+)(\S+?)(?=[,;]?(?:\s|$))/iu',
            fn (array $match): string => $match[1].$match[2].$marker('password', $match[3]), $text);
        $text = (string) preg_replace_callback('/\bsk-[A-Za-z0-9_-]{10,}/', $replace('key'), $text);
        $text = (string) preg_replace_callback('/\b(?=[A-Za-z0-9_-]*\d)(?=[A-Za-z0-9_-]*[A-Za-z])[A-Za-z0-9_-]{32,}\b/', $replace('key'), $text);
        $text = (string) preg_replace_callback('/\b[A-Z]{2}\d{2}(?: ?[A-Z0-9]{4}){3,7}(?: ?[A-Z0-9]{1,3})?\b/', $replace('bank account'), $text);

        // A number may end a sentence ("call +964 750 123 4567."), but not run into a word, path or address.
        return (string) preg_replace_callback('/(?<![\w.:\/-])\+?\d[\d ().-]{6,}\d(?![\w:\/-]|\.\w)/', function (array $match) use ($marker): string {
            $value = $match[0];
            $digits = (string) preg_replace('/\D/', '', $value);

            if (strlen($digits) < 8 || self::looksLikeDateOrAddress($value)) {
                return $value;
            }

            if (strlen($digits) >= 13 && strlen($digits) <= 19 && ! str_starts_with($value, '+') && self::passesLuhn($digits)) {
                return $marker('card number', $value);
            }

            return $marker('phone', $value);
        }, $text);
    }

    private static function looksLikeDateOrAddress(string $value): bool
    {
        return (bool) preg_match('/^(\d{4}[.\/-]\d{1,2}[.\/-]\d{1,2}|\d{1,2}[.\/-]\d{1,2}[.\/-]\d{2,4}|\d{1,3}(\.\d{1,3}){3})$/', trim($value));
    }

    private static function passesLuhn(string $digits): bool
    {
        $sum = 0;
        $double = false;

        for ($index = strlen($digits) - 1; $index >= 0; $index--) {
            $digit = (int) $digits[$index];

            if ($double) {
                $digit *= 2;
                $digit = $digit > 9 ? $digit - 9 : $digit;
            }

            $sum += $digit;
            $double = ! $double;
        }

        return $sum % 10 === 0;
    }
}
