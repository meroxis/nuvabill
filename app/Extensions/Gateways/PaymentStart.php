<?php

namespace App\Extensions\Gateways;

/**
 * What happens when a client chooses a gateway: go to a payment page, read instructions,
 * or scan a QR code with a banking app (for example FIB).
 */
final readonly class PaymentStart
{
    /**
     * @param  array<string, string>  $appLinks  Buttons that open the payment in an app, label => URL.
     */
    private function __construct(
        public ?string $redirectUrl = null,
        public ?string $instructions = null,
        public ?string $qrImage = null,
        public ?string $code = null,
        public array $appLinks = [],
        public ?string $expiresAt = null,
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

    /**
     * A QR code to scan in a banking app, with a code to type in instead and links that open the app.
     *
     * @param  string  $qrImage  A data URL, for example "data:image/png;base64,...".
     * @param  array<string, string>  $appLinks
     */
    public static function qr(string $qrImage, ?string $code = null, array $appLinks = [], ?string $expiresAt = null): self
    {
        return new self(qrImage: $qrImage, code: $code, appLinks: $appLinks, expiresAt: $expiresAt);
    }

    public function isRedirect(): bool
    {
        return $this->redirectUrl !== null;
    }

    public function isQr(): bool
    {
        return $this->qrImage !== null;
    }
}
