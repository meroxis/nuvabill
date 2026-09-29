<?php

namespace App\Extensions\Gateways;

use App\Models\PaymentMethod;

/**
 * A card or PayPal account a gateway kept for later charges, as the gateway reported it.
 */
final readonly class SavedMethod
{
    public function __construct(
        public string $type,
        public string $reference,
        public ?string $customerReference = null,
        public ?string $brand = null,
        public ?string $last4 = null,
        public ?int $expiresMonth = null,
        public ?int $expiresYear = null,
        public ?string $email = null,
    ) {}

    public static function card(string $reference, ?string $customer, ?string $brand, ?string $last4, ?int $month, ?int $year): self
    {
        return new self(PaymentMethod::TYPE_CARD, $reference, $customer, $brand, $last4, $month, $year);
    }

    public static function paypal(string $reference, ?string $customer, ?string $email): self
    {
        return new self(PaymentMethod::TYPE_PAYPAL, $reference, $customer, email: $email);
    }

    /**
     * Kept in a payment result's meta, so the method is saved when the payment is recorded.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'reference' => $this->reference,
            'customer_reference' => $this->customerReference,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expires_month' => $this->expiresMonth,
            'expires_year' => $this->expiresYear,
            'email' => $this->email,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! in_array($data['type'] ?? null, [PaymentMethod::TYPE_CARD, PaymentMethod::TYPE_PAYPAL], true) || blank($data['reference'] ?? null)) {
            return null;
        }

        return new self(
            (string) $data['type'],
            (string) $data['reference'],
            isset($data['customer_reference']) ? (string) $data['customer_reference'] : null,
            isset($data['brand']) ? (string) $data['brand'] : null,
            isset($data['last4']) ? substr((string) $data['last4'], -4) : null,
            isset($data['expires_month']) ? (int) $data['expires_month'] : null,
            isset($data['expires_year']) ? (int) $data['expires_year'] : null,
            isset($data['email']) ? (string) $data['email'] : null,
        );
    }
}
