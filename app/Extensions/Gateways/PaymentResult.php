<?php

namespace App\Extensions\Gateways;

/**
 * A confirmed payment reported by a gateway.
 */
final readonly class PaymentResult
{
    /**
     * @param  int  $amount  Amount received, in minor units.
     * @param  string  $reference  The gateway's own ID for the payment. Used to ignore duplicate notifications.
     * @param  int  $fee  Gateway fee in minor units, when the gateway reports it.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $invoiceId,
        public int $amount,
        public string $currency,
        public string $reference,
        public int $fee = 0,
        public array $meta = [],
    ) {}
}
