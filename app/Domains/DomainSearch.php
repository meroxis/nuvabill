<?php

namespace App\Domains;

use App\Models\TldPrice;
use Illuminate\Support\Collection;

/**
 * Searches a name across the extensions for sale: the domain search page and order forms use it.
 */
class DomainSearch
{
    /**
     * How many extensions are checked when the visitor types a name without one.
     */
    public const SUGGESTIONS = 8;

    public function __construct(private AvailabilityChecker $checker) {}

    /**
     * @return Collection<int, TldPrice>
     */
    public function prices(string $currency): Collection
    {
        return TldPrice::query()->enabled($currency)->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('tld')->get();
    }

    /**
     * @param  Collection<int, TldPrice>|null  $prices
     * @return Collection<int, array{domain: string, tld: TldPrice|null, available: bool|null, exact: bool}>
     */
    public function search(string $query, string $currency, ?Collection $prices = null, int $suggestions = self::SUGGESTIONS): Collection
    {
        $prices ??= $this->prices($currency);
        $full = DomainName::normalize($query);

        if ($full !== null && substr_count($full, '.') >= 1) {
            [$label, $tld] = DomainName::split($full, $prices->pluck('tld'));
        } else {
            [$label, $tld] = [DomainName::label($query), null];
        }

        if ($label === null || $label === '' || str_contains($label, '.')) {
            return collect();
        }

        $tlds = $prices->pluck('tld')->reject(fn (string $item): bool => $item === $tld)->take($suggestions)->values();

        if ($tld !== null) {
            $tlds->prepend($tld);
        }

        $domains = $tlds->map(fn (string $item): string => $label.'.'.$item)->values()->all();
        $answers = $this->checker->check($domains, $currency);

        return collect($domains)->map(fn (string $domain, int $index): array => [
            'domain' => $domain,
            'tld' => $prices->firstWhere('tld', $tlds[$index]),
            'available' => $answers[$domain] ?? null,
            'exact' => $tld !== null && $index === 0,
        ]);
    }
}
