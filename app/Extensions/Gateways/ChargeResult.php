<?php

namespace App\Extensions\Gateways;

/**
 * What happened when a saved card or PayPal account was charged without the client.
 */
final readonly class ChargeResult
{
    public const PAID = 'paid';

    public const FAILED = 'failed';

    /**
     * The bank wants the client to confirm the payment themselves (for example 3-D Secure).
     * Trying again without the client cannot work.
     */
    public const NEEDS_CLIENT = 'needs_client';

    private function __construct(
        public string $status,
        public ?PaymentResult $payment = null,
        public string $message = '',
    ) {}

    public static function paid(PaymentResult $payment): self
    {
        return new self(self::PAID, $payment);
    }

    /**
     * Nothing was left to charge: the wallet paid the invoice, or it was paid another way.
     */
    public static function settled(): self
    {
        return new self(self::PAID);
    }

    /**
     * @param  string  $message  Why, in words a client understands, for example "The bank declined the card."
     */
    public static function failed(string $message): self
    {
        return new self(self::FAILED, message: $message);
    }

    public static function needsClient(string $message): self
    {
        return new self(self::NEEDS_CLIENT, message: $message);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}
