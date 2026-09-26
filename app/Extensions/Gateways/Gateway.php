<?php

namespace App\Extensions\Gateways;

use App\Contracts\PaymentGateway;
use App\Extensions\ExtensionManifest;
use App\Models\Invoice;
use Illuminate\Http\Request;

/**
 * Base class for payment gateway extensions. Holds the manifest and the saved settings.
 */
abstract class Gateway implements PaymentGateway
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        protected ExtensionManifest $manifest,
        protected array $settings = [],
    ) {}

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function name(): string
    {
        $displayName = $this->setting('display_name');

        return is_string($displayName) && $displayName !== '' ? $displayName : $this->manifest->name;
    }

    public function settingsFields(): array
    {
        return [];
    }

    public function isConfigured(): bool
    {
        foreach ($this->settingsFields() as $key => $field) {
            if (($field['required'] ?? false) && blank($this->setting($key))) {
                return false;
            }
        }

        return true;
    }

    public function supportsCurrency(string $currency): bool
    {
        return true;
    }

    public function handleReturn(Request $request, Invoice $invoice): ?PaymentResult
    {
        return null;
    }

    public function handleWebhook(Request $request): WebhookResult
    {
        return WebhookResult::ignored();
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
