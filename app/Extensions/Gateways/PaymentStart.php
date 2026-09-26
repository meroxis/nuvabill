<?php

namespace App\Extensions\Gateways;

/**
 * What happens when a client chooses a gateway: go to a payment page, or read instructions.
 */
final readonly class PaymentStart
{
    private function __construct(
        public ?string $redirectUrl = null,
        public ?string $instructions = null,
    ) {}

    public static function redirect(string $url): self
    {
        return new self(redirectUrl: $url);
    }

    /**
     * Instructions in Markdown, shown on the invoice page (for example bank details).
     */
    public static function instructions(string $markdown): self
    {
        return new self(instructions: $markdown);
    }

    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }
}
