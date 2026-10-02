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

    /**
     * The result is not known yet: the bank is still processing the payment, or the gateway did
     * not answer. Nuvabill checks it before it charges the invoice again, so it is never paid twice.
     */
    public const PENDING = 'pending';

    private function __construct(
        public string $status,
        public ?PaymentResult $payment = null,
        public string $message = '',
        public ?string $reference = null,
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

    /**
     * @param  string|null  $reference  The gateway's ID for the payment, when it sent one.
     */
    public static function pending(string $message, ?string $reference = null): self
    {
        return new self(self::PENDING, message: $message, reference: $reference);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
