<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Product;
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
            'product_id' => $product->id,
            'billing_cycle' => $cycle->value,
            'domain' => $domain,
        ];

        $this->session->put(self::SESSION_KEY, $items);
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
     * Cart lines with current prices. Lines for hidden, sold-out or unpriced products are dropped.
     *
     * @return Collection<int, CartLine>
     */
    public function lines(string $currency): Collection
    {
        $items = $this->rawItems();
        $products = Product::query()->with('prices')->whereIn('id', array_column($items, 'product_id'))->get()->keyBy('id');

        return collect($items)
            ->map(function (array $item, int $index) use ($products, $currency): ?CartLine {
                $product = $products->get($item['product_id']);
                $cycle = BillingCycle::tryFrom($item['billing_cycle']);

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
     * @return list<array{product_id: int, billing_cycle: string, domain: string|null}>
     */
    private function rawItems(): array
    {
        return array_values((array) $this->session->get(self::SESSION_KEY, []));
    }
}
