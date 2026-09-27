<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A client placed an order. The order, its first invoice, services and domains exist.
 */
class OrderPlaced
{
    use Dispatchable;

    public function __construct(public Order $order) {}
}
