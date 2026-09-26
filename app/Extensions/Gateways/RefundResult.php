<?php

namespace App\Extensions\Gateways;

/**
 * Money a gateway has sent back to the client.
 */
final readonly class RefundResult
{
    /**
     * @param  int  $amount  Amount refunded, in minor units.
     * @param  string  $reference  The gateway's own ID for the refund.
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public int $amount,
        public string $reference,
        public array $meta = [],
    ) {}
}
