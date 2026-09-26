<?php

namespace App\Billing;

use App\Domains\DomainName;
use App\Enums\BillingCycle;
use App\Models\Domain;
use App\Models\Product;
use App\Models\TldPrice;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * The visitor's shopping cart, kept in the session until checkout.
 */
class Cart
{
    private const SESSION_KEY = 'cart.items';

    public function __construct(private Session $session) {}

    public function add(Product $product, BillingCycle $cycle, ?string $domain): void
    {
        $items = $this->rawItems();
        $items[] = [
            'kind' => CartLine::KIND_PRODUCT,
            'product_id' => $product->id,
            'billing_cycle' => $cycle->value,
            'domain' => $domain,
        ];

        $this->session->put(self::SESSION_KEY, $items);
    }

    /**
     * Add a domain registration or transfer. Adding the same domain again replaces it.
     */
    public function addDomain(string $domain, string $action, int $years, ?string $eppCode = null): void
    {
        $items = array_values(array_filter(
            $this->rawItems(),
            fn (array $item): bool => ($item['kind'] ?? CartLine::KIND_PRODUCT) !== CartLine::KIND_DOMAIN || $item['domain'] !== $domain,
        ));

        $items[] = [
            'kind' => CartLine::KIND_DOMAIN,
            'domain' => $domain,
            'action' => $action === Domain::TYPE_TRANSFER ? Domain::TYPE_TRANSFER : Domain::TYPE_REGISTER,
            'years' => max(1, $years),
            'epp_code' => $eppCode,
        ];

        $this->session->put(self::SESSION_KEY, $items);
    }

    public function hasDomain(string $domain): bool
    {
        return collect($this->rawItems())->contains(fn (array $item): bool => ($item['kind'] ?? null) === CartLine::KIND_DOMAIN && $item['domain'] === $domain);
    }

    public function remove(int $index): void
    {
        $items = $this->rawItems();
        unset($items[$index]);

        $this->session->put(self::SESSION_KEY, array_values($items));
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    public function count(): int
    {
        return count($this->rawItems());
    }

    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Cart lines with current prices. Lines for hidden, sold-out or unpriced products and extensions are dropped.
     *
     * @return Collection<int, CartLine>
     */
    public function lines(string $currency): Collection
    {
        $items = $this->rawItems();
        $products = Product::query()->with('prices', 'server')->whereIn('id', array_filter(array_column($items, 'product_id')))->get()->keyBy('id');
        $tlds = TldPrice::query()->enabled($currency)->get();

        return collect($items)
            ->map(function (array $item, int $index) use ($products, $tlds, $currency): ?CartLine {
                if (($item['kind'] ?? CartLine::KIND_PRODUCT) === CartLine::KIND_DOMAIN) {
                    return $this->domainLine($index, $item, $tlds);
                }

                $product = $products->get($item['product_id'] ?? 0);
                $cycle = BillingCycle::tryFrom((string) ($item['billing_cycle'] ?? ''));

                if ($product === null || $cycle === null || ! $product->is_visible || ! $product->isInStock()) {
                    return null;
                }

                $price = $product->priceFor($currency, $cycle);

                if ($price === null) {
                    return null;
                }

                return new CartLine($index, $product, $cycle, $item['domain'] ?? null, $price->price, $price->setup_fee);
            })
            ->filter()
            ->values();
    }

    public function total(string $currency): int
    {
        return $this->lines($currency)->sum(fn (CartLine $line): int => $line->dueToday());
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, TldPrice>  $tlds
     */
    private function domainLine(int $index, array $item, Collection $tlds): ?CartLine
    {
        $domain = DomainName::normalize((string) ($item['domain'] ?? ''));

        if ($domain === null) {
            return null;
        }

        [, $tld] = DomainName::split($domain, $tlds->pluck('tld'));
        $price = $tlds->firstWhere('tld', $tld);

        if ($price === null) {
            return null;
        }

        $years = min(max((int) ($item['years'] ?? 1), $price->min_years), $price->max_years);

        return CartLine::forDomain($index, $domain, (string) ($item['action'] ?? Domain::TYPE_REGISTER), $years, $price, $item['epp_code'] ?? null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rawItems(): array
    {
        return array_values((array) $this->session->get(self::SESSION_KEY, []));
    }
}
