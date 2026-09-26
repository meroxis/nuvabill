<?php

namespace App\Domains;

use App\Contracts\DomainRegistrar;
use App\Extensions\ExtensionManager;
use App\Models\TldPrice;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Finds out which domains are free to register. Asks the registrar set for each extension,
 * or RDAP when the extension has no registrar. Answers are kept for a few minutes.
 */
class AvailabilityChecker
{
    private const CACHE_SECONDS = 300;

    public function __construct(
        private ExtensionManager $extensions,
        private Rdap $rdap,
    ) {}

    /**
     * @param  list<string>  $domains  Full, normalized names.
     * @return array<string, bool|null> True when free, false when taken, null when unknown.
     */
    public function check(array $domains, string $currency): array
    {
        $results = [];
        $toAsk = [];

        foreach (array_unique($domains) as $domain) {
            $cached = Cache::get($this->cacheKey($domain));

            if (is_bool($cached)) {
                $results[$domain] = $cached;
            } else {
                $toAsk[] = $domain;
            }
        }

        $prices = TldPrice::query()->enabled($currency)->get();
        $registrars = $this->extensions->activeRegistrars();
        $byRegistrar = [];

        foreach ($toAsk as $domain) {
            [, $tld] = DomainName::split($domain, $prices->pluck('tld'));
            $slug = $prices->firstWhere('tld', $tld)?->registrar;
            $byRegistrar[$slug !== null && $registrars->has($slug) ? $slug : ''][] = $domain;
        }

        foreach ($byRegistrar as $slug => $group) {
            $answers = $slug === '' ? $this->askRdap($group) : $this->askRegistrar($registrars->get($slug), $group);

            foreach ($group as $domain) {
                $results[$domain] = $answers[$domain] ?? null;

                if (is_bool($results[$domain])) {
                    Cache::put($this->cacheKey($domain), $results[$domain], self::CACHE_SECONDS);
                }
            }
        }

        return array_merge(array_fill_keys($domains, null), $results);
    }

    public function isAvailable(string $domain, string $currency): ?bool
    {
        return $this->check([$domain], $currency)[$domain] ?? null;
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, bool|null>
     */
    private function askRegistrar(DomainRegistrar $registrar, array $domains): array
    {
        try {
            return $registrar->checkAvailability($domains);
        } catch (Throwable $exception) {
            report($exception);

            return $this->askRdap($domains);
        }
    }

    /**
     * @param  list<string>  $domains
     * @return array<string, bool|null>
     */
    private function askRdap(array $domains): array
    {
        $answers = [];

        foreach ($domains as $domain) {
            $registered = $this->rdap->isRegistered($domain);
            $answers[$domain] = $registered === null ? null : ! $registered;
        }

        return $answers;
    }

    private function cacheKey(string $domain): string
    {
        return 'nuvabill.domain-available.'.$domain;
    }
}
