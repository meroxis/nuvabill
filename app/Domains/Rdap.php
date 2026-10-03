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

    private const CACHE_KEY = 'nuvabill.rdap.servers';

    /**
     * How long to wait before downloading the list again after IANA did not answer.
     */
    private const RETRY_MINUTES = 10;

    /**
     * The list for this request, so it is read at most once however many names are checked.
     *
     * @var array<string, string>|null
     */
    private ?array $servers = null;

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
        if ($this->servers !== null) {
            return $this->servers;
        }

        $servers = Cache::get(self::CACHE_KEY);

        if (is_array($servers)) {
            return $this->servers = $servers;
        }

        try {
            $response = Http::connectTimeout(5)->timeout(10)->acceptJson()->get(self::BOOTSTRAP_URL);
        } catch (Throwable) {
            return $this->unreachable();
        }

        if (! $response->successful()) {
            return $this->unreachable();
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

        Cache::put(self::CACHE_KEY, $servers, now()->addDay());

        return $this->servers = $servers;
    }

    /**
     * IANA did not answer. Remember that for a few minutes, so a search over many names, and the
     * searches after it, do not each wait for the download again.
     *
     * @return array<string, string>
     */
    private function unreachable(): array
    {
        Cache::put(self::CACHE_KEY, [], now()->addMinutes(self::RETRY_MINUTES));

        return $this->servers = [];
    }
}
