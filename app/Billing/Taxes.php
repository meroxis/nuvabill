<?php

namespace App\Billing;

use App\Models\Client;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\Service;
use App\Models\TaxRule;
use Illuminate\Support\Collection;

/**
 * Decides who pays tax, on which invoice lines, and how much.
 *
 * One rule applies per client: the most specific match for their country and state, then their
 * country, then a rule for everyone. Tax-exempt clients (for example businesses with a VAT number)
 * pay none. Prices either have the tax added on top, or already include it ("inclusive").
 */
class Taxes
{
    /**
     * @var Collection<int, TaxRule>|null
     */
    private ?Collection $rules = null;

    public function enabled(): bool
    {
        return (bool) setting('tax.enabled');
    }

    public function inclusive(): bool
    {
        return (bool) setting('tax.inclusive');
    }

    /**
     * The rule for a client, or for a visitor's country before they sign up. Null means no tax.
     */
    public function ruleFor(?Client $client, ?string $country = null, ?string $state = null): ?TaxRule
    {
        if (! $this->enabled() || ($client?->tax_exempt ?? false)) {
            return null;
        }

        $country = strtoupper(trim((string) ($country ?? $client?->country)));
        $state = trim((string) ($state ?? $client?->state));
        $rules = $this->rules ??= TaxRule::query()->orderBy('id')->get();

        return $rules->first(fn (TaxRule $rule): bool => $country !== '' && $rule->country === $country && filled($rule->state) && strcasecmp((string) $rule->state, $state) === 0)
            ?? $rules->first(fn (TaxRule $rule): bool => $country !== '' && $rule->country === $country && blank($rule->state))
            ?? $rules->first(fn (TaxRule $rule): bool => $rule->country === null);
    }

    /**
     * Whether an invoice line, as passed to {@see InvoiceManager::create()}, is taxed. A line can
     * decide for itself with a "taxed" key; otherwise products follow their taxable switch,
     * domains follow the domain setting, and wallet top-ups are never taxed.
     *
     * @param  array<string, mixed>  $item
     */
    public function isTaxable(array $item): bool
    {
        if (array_key_exists('taxed', $item)) {
            return (bool) $item['taxed'];
        }

        $type = (string) ($item['type'] ?? InvoiceItem::TYPE_MANUAL);

        return match (true) {
            $type === InvoiceItem::TYPE_CREDIT => false,
            in_array($type, InvoiceItem::DOMAIN_TYPES, true) => (bool) setting('tax.domains'),
            filled($item['service_id'] ?? null) => (bool) (Service::query()->with('product')->find($item['service_id'])?->product?->taxable ?? true),
            filled($item['domain_id'] ?? null) => (bool) setting('tax.domains'),
            default => true,
        };
    }

    public function productIsTaxable(?Product $product): bool
    {
        return $product === null ? (bool) setting('tax.domains') : $product->taxable;
    }

    /**
     * The tax on a taxable amount in minor units: added on top, or the part already inside the price.
     */
    public static function amount(int $taxable, int $rate, bool $inclusive): int
    {
        if ($taxable <= 0 || $rate <= 0) {
            return 0;
        }

        return (int) round($inclusive ? $taxable * $rate / (10000 + $rate) : $taxable * $rate / 10000);
    }

    /**
     * The tax and total for cart lines, before an invoice exists. Visitors who are not signed in are
     * estimated with the country they entered, or with the rule for everyone.
     *
     * @param  Collection<int, CartLine>  $lines
     * @return array{name: string|null, rate: int|null, label: string|null, tax: int, total: int, inclusive: bool}
     */
    public function forCart(Collection $lines, ?Client $client, ?string $country = null, ?string $state = null): array
    {
        $dueToday = (int) $lines->sum(fn (CartLine $line): int => $line->dueToday());
        $rule = $this->ruleFor($client, $client === null ? $country : null, $client === null ? $state : null);

        if ($rule === null) {
            return ['name' => null, 'rate' => null, 'label' => null, 'tax' => 0, 'total' => $dueToday, 'inclusive' => false];
        }

        $taxable = (int) $lines->filter(fn (CartLine $line): bool => $this->productIsTaxable($line->product))->sum(fn (CartLine $line): int => $line->dueToday());
        $inclusive = $this->inclusive();
        $tax = self::amount($taxable, $rule->rate, $inclusive);

        return [
            'name' => $rule->name,
            'rate' => $rule->rate,
            'label' => $rule->name.' ('.$rule->percentLabel().')',
            'tax' => $tax,
            'total' => $inclusive ? $dueToday : $dueToday + $tax,
            'inclusive' => $inclusive,
        ];
    }
}
