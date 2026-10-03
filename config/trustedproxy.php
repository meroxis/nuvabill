<?php

use App\Support\Cloudflare;

$proxies = env('NUVABILL_TRUSTED_PROXIES');

return [

    /*
    |--------------------------------------------------------------------------
    | Trusted proxies
    |--------------------------------------------------------------------------
    |
    | Set NUVABILL_TRUSTED_PROXIES when a proxy sits in front of Nuvabill, so
    | sign-in limits and logs see the visitor's real IP address:
    |
    |   cloudflare       the site is behind Cloudflare
    |   10.0.0.1,10.0/8  a list of proxy IP addresses or ranges
    |   *                any proxy (only when the server is not reachable directly)
    |
    | Not set means no proxy is trusted. It is an empty list, not null: with null, Laravel trusts
    | every proxy when the visitor's Host header ends in .on-forge.com or .on-vapor.com.
    |
    */

    'proxies' => match (true) {
        blank($proxies) => [],
        $proxies === 'cloudflare' => Cloudflare::IP_RANGES,
        $proxies === '*' => '*',
        default => array_map(trim(...), explode(',', $proxies)),
    },

];
