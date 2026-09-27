<?php

namespace App\Billing;

use App\Domains\DomainName;
use App\Enums\BillingCycle;
use App\Models\Client;
use App\Models\Coupon;
use App\Models\Domain;
use App\Models\Product;
use App\Models\ProductAddon;
use App\Models\TldPrice;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;

/**
 * The visitor's shopping cart, kept in the session until checkout.
 */
class Cart
{
    private const SESSION_KEY = 'cart.items';

    private const COUPON_KEY = 'cart.coupon';

    public function __construct(private Session $session) {}

    /**
     * @param  list<int>  $addonIds  Product add-ons the client ticked.
     */
    public function add(Product $product, BillingCycle $cycle, ?string $domain, array $addonIds = []): void
    {
        $items = $this->rawItems();
        $items[] = [
            'kind' => CartLine::KIND_PRODUCT,
            'product_id' => $product->id,
            'billing_cycle' => $cycle->value,
            'domain' => $domain,
            'addons' => array_values(array_unique(array_map('intval', $addonIds))),
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
        $this->session->forget([self::SESSION_KEY, self::COUPON_KEY]);
    }

    public function setCoupon(?string $code): void
    {
        $code = $code === null ? '' : Coupon::normalize($code);

        $code === '' ? $this->session->forget(self::COUPON_KEY) : $this->session->put(self::COUPON_KEY, $code);
    }

    public function couponCode(): ?string
    {
        $code = $this->session->get(self::COUPON_KEY);

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * The entered coupon if it can be used now by this client (or visitor), otherwise null.
     */
    public function coupon(string $currency, ?Client $client = null): ?Coupon
    {
        $coupon = $this->couponCode() === null ? null : Coupon::findByCode($this->couponCode());

        return $coupon !== null && $coupon->unavailableReason($client ?? $this->client(), $currency) === null ? $coupon : null;
    }

    /**
     * Why the entered coupon does not work, or null when there is none or it works.
     */
    public function couponProblem(string $currency, ?Client $client = null): ?string
    {
        if ($this->couponCode() === null) {
            return null;
        }

        $coupon = Coupon::findByCode($this->couponCode());

        return $coupon === null ? __('We do not know the coupon :code.', ['code' => $this->couponCode()]) : $coupon->unavailableReason($client ?? $this->client(), $currency);
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
     * With a working coupon, each line carries its discount.
     *
     * @return Collection<int, CartLine>
     */
    public function lines(string $currency, ?Client $client = null): Collection
    {
        $items = $this->rawItems();
        $products = Product::query()->with('prices', 'server')->whereIn('id', array_filter(array_column($items, 'product_id')))->get()->keyBy('id');
        $addons = ProductAddon::query()->visible()->with('prices')->whereIn('id', collect($items)->pluck('addons')->flatten()->filter()->all())->get()->keyBy('id');
        $tlds = TldPrice::query()->enabled($currency)->get();
        $coupon = $this->coupon($currency, $client);

        return collect($items)
            ->map(function (array $item, int $index) use ($products, $addons, $tlds, $currency): ?CartLine {
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

                return new CartLine($index, $product, $cycle, $item['domain'] ?? null, $price->price, $price->setup_fee, addons: $this->addonLines($item, $product, $cycle, $currency, $addons));
            })
            ->filter()
            ->map(fn (CartLine $line): CartLine => $coupon === null ? $line : $line->withDiscount($this->discountFor($coupon, $line)))
            ->values();
    }

    public function total(string $currency, ?Client $client = null): int
    {
        return $this->lines($currency, $client)->sum(fn (CartLine $line): int => $line->dueToday());
    }

    public function discount(string $currency, ?Client $client = null): int
    {
        return $this->lines($currency, $client)->sum(fn (CartLine $line): int => $line->discount);
    }

    /**
     * A coupon takes money off the product price (not setup fees or add-ons), and off domains
     * when staff allowed that.
     */
    private function discountFor(Coupon $coupon, CartLine $line): int
    {
        if ($line->isDomain()) {
            return $coupon->applies_to_domains ? $coupon->discountOn($line->price) : 0;
        }

        return $coupon->appliesToProduct($line->product, $line->cycle) ? $coupon->discountOn($line->price) : 0;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  Collection<int, ProductAddon>  $addons
     * @return list<array{id: int, name: string, price: int, setup_fee: int}>
     */
    private function addonLines(array $item, Product $product, BillingCycle $cycle, string $currency, Collection $addons): array
    {
        $lines = [];

        foreach ((array) ($item['addons'] ?? []) as $id) {
            $addon = $addons->get((int) $id);
            $price = $addon?->appliesTo($product) ? $addon->priceFor($currency, $cycle) : null;

            if ($price !== null) {
                $lines[] = ['id' => $addon->id, 'name' => $addon->name, 'price' => $price->price, 'setup_fee' => $price->setup_fee];
            }
        }

        return $lines;
    }

    private function client(): ?Client
    {
        $client = auth('web')->user();

        return $client instanceof Client ? $client : null;
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
