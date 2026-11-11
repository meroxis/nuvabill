<?php

namespace App\Marketplace\Store;

/**
 * The network an internet address belongs to, for counting where calls to the store come from.
 * One place can have many addresses (a whole IPv6 range, or an office behind a few IPv4
 * addresses), so limits and counts go by network: the /24 of an IPv4 address and the /48 of an
 * IPv6 address.
 */
class SourceNetwork
{
    /**
     * For example "203.0.113.0/24" or "2001:db8:12::/48". Null when the text is not an address.
     */
    public static function of(?string $ip): ?string
    {
        $binary = $ip === null ? false : @inet_pton(trim($ip));

        if ($binary === false) {
            return null;
        }

        // An IPv4 address written as IPv6 (::ffff:203.0.113.9) is the same place as the IPv4 address.
        if (strlen($binary) === 16 && str_starts_with($binary, str_repeat("\0", 10)."\xff\xff")) {
            $binary = substr($binary, 12);
        }

        return strlen($binary) === 4
            ? inet_ntop(substr($binary, 0, 3)."\0").'/24'
            : inet_ntop(substr($binary, 0, 6).str_repeat("\0", 10)).'/48';
    }
}
