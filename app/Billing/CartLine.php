<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Domain;
use App\Models\Product;
use App\Models\TldPrice;

/**
 * One item in the shopping cart with its price resolved: a product, or a domain registration or transfer.
 */
final readonly class CartLine
{
    public const KIND_PRODUCT = 'product';

    public const KIND_DOMAIN = 'domain';

    public function __construct(
        public int $index,
        public ?Product $product,
        public ?BillingCycle $cycle,
        public ?string $domain,
        public int $price,
        public int $setupFee = 0,
        public string $kind = self::KIND_PRODUCT,
        public string $domainAction = Domain::TYPE_REGISTER,
        public int $years = 1,
        public ?string $eppCode = null,
        public ?TldPrice $tldPrice = null,
    ) {}

    public static function forDomain(int $index, string $domain, string $action, int $years, TldPrice $tldPrice, ?string $eppCode = null): self
    {
        return new self(
            index: $index,
            product: null,
            cycle: null,
            domain: $domain,
            price: $tldPrice->priceFor($action, $years),
            kind: self::KIND_DOMAIN,
            domainAction: $action,
            years: $years,
            eppCode: $eppCode,
            tldPrice: $tldPrice,
        );
    }

    public function isDomain(): bool
    {
        return $this->kind === self::KIND_DOMAIN;
    }

    public function dueToday(): int
    {
        return $this->price + $this->setupFee;
    }

    /**
     * What the line is, for example "Business hosting" or "Domain registration".
     */
    public function title(): string
    {
        if (! $this->isDomain()) {
            return (string) $this->product?->name;
        }

        return $this->domainAction === Domain::TYPE_TRANSFER ? __('Domain transfer') : __('Domain registration');
    }

    /**
     * The details under the title, for example "example.com · Monthly" or "example.com · 2 years".
     */
    public function summary(): string
    {
        $period = $this->isDomain()
            ? trans_choice(':count year|:count years', $this->years, ['count' => $this->years])
            : (string) $this->cycle?->label();

        return ($this->domain ? $this->domain.' · ' : '').$period;
    }
}
