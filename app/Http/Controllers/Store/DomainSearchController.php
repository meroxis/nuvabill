<?php

namespace App\Http\Controllers\Store;

use App\Domains\AvailabilityChecker;
use App\Domains\DomainName;
use App\Http\Controllers\Controller;
use App\Models\TldPrice;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The public domain search: type a name, see which extensions are free and what they cost.
 */
class DomainSearchController extends Controller
{
    /**
     * How many extensions are checked when the visitor types a name without one.
     */
    private const SUGGESTIONS = 8;

    public function __invoke(Request $request, AvailabilityChecker $checker): View
    {
        $currency = auth('web')->user()?->currency ?? (string) setting('billing.currency');
        $prices = TldPrice::query()->enabled($currency)->orderByDesc('is_featured')->orderBy('sort_order')->orderBy('tld')->get();
        $query = trim((string) $request->query('q', ''));

        return view('theme::store.domains', [
            'query' => $query,
            'results' => $query === '' ? collect() : $this->search($query, $prices, $checker, $currency),
            'invalid' => $query !== '' && DomainName::normalize($query) === null && DomainName::label($query) === null,
            'prices' => $prices,
            'currency' => $currency,
        ]);
    }

    /**
     * @param  Collection<int, TldPrice>  $prices
     * @return Collection<int, array{domain: string, tld: TldPrice|null, available: bool|null, exact: bool}>
     */
    private function search(string $query, Collection $prices, AvailabilityChecker $checker, string $currency): Collection
    {
        $full = DomainName::normalize($query);

        if ($full !== null && substr_count($full, '.') >= 1) {
            [$label, $tld] = DomainName::split($full, $prices->pluck('tld'));
        } else {
            [$label, $tld] = [DomainName::label($query), null];
        }

        if ($label === null || $label === '' || str_contains($label, '.')) {
            return collect();
        }

        $tlds = $prices->pluck('tld')->reject(fn (string $item): bool => $item === $tld)->take(self::SUGGESTIONS)->values();

        if ($tld !== null) {
            $tlds->prepend($tld);
        }

        $domains = $tlds->map(fn (string $item): string => $label.'.'.$item)->values()->all();
        $answers = $checker->check($domains, $currency);

        return collect($domains)->map(fn (string $domain, int $index): array => [
            'domain' => $domain,
            'tld' => $prices->firstWhere('tld', $tlds[$index]),
            'available' => $answers[$domain] ?? null,
            'exact' => $tld !== null && $index === 0,
        ]);
    }
}
