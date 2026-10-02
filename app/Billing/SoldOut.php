<?php

namespace App\Billing;

use App\Models\Product;
use RuntimeException;

/**
 * An order asked for more of a product than is left in stock. Nothing of the order was made.
 */
class SoldOut extends RuntimeException
{
    public function __construct(public readonly Product $product, public readonly int $left)
    {
        parent::__construct($left > 0
            ? __('Only :count left of :product. Remove the extra ones from your cart.', ['count' => $left, 'product' => $product->name])
            : __(':product is sold out. Remove it from your cart.', ['product' => $product->name]));
    }
}
