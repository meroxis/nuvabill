<?php

namespace App\Billing;

use App\Enums\BillingCycle;
use App\Models\Product;

/**
 * One product in the shopping cart, with its price resolved.
 */
final readonly class CartLine
{
    public function __construct(
        public int $index,
        public Product $product,
        public BillingCycle $cycle,
        public ?string $domain,
        public int $price,
        public int $setupFee,
    ) {}

    public function dueToday(): int
    {
        return $this->price + $this->setupFee;
    }
}
