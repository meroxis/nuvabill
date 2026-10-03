<?php

namespace App\Domains;

/**
 * Cleans up and splits domain names typed by people, for example "https://www.Example.com/" into "example.com".
 */
final class DomainName
{
    private const LABEL = '(?!-)[a-z0-9-]{1,63}(?<!-)';

    /**
     * A lowercase domain name without protocol, "www." or path, or null when it is not a valid name.
     * Names in other scripts (for example Arabic) are converted to their "xn--" form.
     */
    public static function normalize(?string $input): ?string
    {
        $name = mb_strtolower(trim((string) $input));
        $name = (string) preg_replace('#^[a-z]+://#', '', $name);
        $name = (string) preg_replace('#[/?\#].*$#', '', $name);
        $name = rtrim($name, '.');

        if (str_starts_with($name, 'www.') && substr_count($name, '.') >= 2) {
            $name = substr($name, 4);
        }

        if ($name !== '' && preg_match('/[^\x00-\x7F]/', $name) && function_exists('idn_to_ascii')) {
            $name = (string) idn_to_ascii($name, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        }

        return self::isValid($name) ? $name : null;
    }

    public static function isValid(string $name): bool
    {
        return strlen($name) <= 253
            && (bool) preg_match('/^'.self::LABEL.'(\.'.self::LABEL.')*\.([a-z]{2,63}|xn--[a-z0-9-]{1,59})$/', $name);
    }

    /**
     * A single label a person searched for, for example "my-shop" from "My Shop". Null when nothing usable is left.
     */
    public static function label(?string $input): ?string
    {
        $label = (string) preg_replace('/[^a-z0-9-]+/', '-', mb_strtolower(trim((string) $input)));
        $label = trim($label, '-');

        return $label !== '' && strlen($label) <= 63 && preg_match('/^'.self::LABEL.'$/', $label) ? $label : null;
    }

    /**
     * Split a normalized name into [name, extension] using the longest known extension, so
     * "shop.co.uk" becomes ["shop", "co.uk"] when "co.uk" is sold. Falls back to the last label.
     *
     * @param  iterable<string>  $knownTlds
     * @return array{0: string, 1: string}
     */
    public static function split(string $domain, iterable $knownTlds = []): array
    {
        $best = null;

        foreach ($knownTlds as $tld) {
            $tld = strtolower(ltrim($tld, '.'));

            if (str_ends_with($domain, '.'.$tld) && ($best === null || strlen($tld) > strlen($best))) {
                $best = $tld;
            }
        }

        $best ??= substr($domain, strrpos($domain, '.') + 1);

        return [substr($domain, 0, -strlen($best) - 1), $best];
    }

    /**
     * Whether a normalized name can be registered or transferred: one name in front of the
     * extension, such as "shop.co.uk" with "co.uk" sold. "blog.example.com" is a subdomain:
     * fine for hosting, but no registrar can register it.
     *
     * @param  iterable<string>  $knownTlds
     */
    public static function isRegistrable(string $domain, iterable $knownTlds = []): bool
    {
        if (! str_contains($domain, '.')) {
            return false;
        }

        [$name] = self::split($domain, $knownTlds);

        return $name !== '' && ! str_contains($name, '.');
    }
}
