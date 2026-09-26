<?php

namespace App\Domains;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks whether a domain is registered with RDAP, the public successor of WHOIS. Used for
 * extensions that have no registrar module. The list of RDAP servers comes from IANA.
 */
class Rdap
{
    public const BOOTSTRAP_URL = 'https://data.iana.org/rdap/dns.json';

    /**
     * True when registered, false when free, null when the answer is unknown
     * (the extension has no RDAP server or the server did not answer).
     */
    public function isRegistered(string $domain): ?bool
    {
        $server = $this->serverFor(substr($domain, strrpos($domain, '.') + 1));

        if ($server === null) {
            return null;
        }

        try {
            $response = Http::timeout(8)->accept('application/rdap+json')->get(rtrim($server, '/').'/domain/'.$domain);
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            $response->status() === 404 => false,
            $response->successful() => true,
            default => null,
        };
    }

    /**
     * The RDAP base URL for a top-level extension such as "com", or null if IANA lists none.
     */
    public function serverFor(string $tld): ?string
    {
        return $this->servers()[strtolower($tld)] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function servers(): array
    {
        $servers = Cache::get('nuvabill.rdap.servers');

        if (is_array($servers)) {
            return $servers;
        }

        try {
            $response = Http::timeout(10)->acceptJson()->get(self::BOOTSTRAP_URL);
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $servers = [];

        foreach ((array) $response->json('services', []) as $service) {
            [$tlds, $urls] = [(array) ($service[0] ?? []), (array) ($service[1] ?? [])];
            $url = collect($urls)->first(fn (mixed $url): bool => is_string($url) && str_starts_with($url, 'https://'));

            foreach ($tlds as $tld) {
                if (is_string($tld) && is_string($url)) {
                    $servers[strtolower($tld)] = $url;
                }
            }
        }

        Cache::put('nuvabill.rdap.servers', $servers, now()->addDay());

        return $servers;
    }
}
