<?php

namespace App\Extensions\Gateways;

/**
 * The outcome of a gateway notification (webhook).
 */
final readonly class WebhookResult
{
    private function __construct(
        public int $status,
        public string $message,
        public ?PaymentResult $payment = null,
        public ?PaymentReversal $reversal = null,
    ) {}

    public static function paid(PaymentResult $payment): self
    {
        return new self(200, 'ok', $payment);
    }

    /**
     * Money of a payment went back without this site sending it: a refund made at the payment
     * service, or a dispute. It is recorded once on the payment's invoice.
     */
    public static function reversed(PaymentReversal $reversal): self
    {
        return new self(200, 'ok', reversal: $reversal);
    }

    /**
     * A valid notification that needs no action, such as an event type we do not use.
     */
    public static function ignored(string $message = 'ignored'): self
    {
        return new self(200, $message);
    }

    /**
     * A notification that failed verification. Nothing is recorded.
     */
    public static function invalid(string $message): self
    {
        return new self(400, $message);
    }
}
