<?php

namespace App\Events;

use App\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * An invoice became fully paid, after its services were renewed or queued for setup.
 */
class InvoicePaid
{
    use Dispatchable;

    public function __construct(public Invoice $invoice) {}
}
