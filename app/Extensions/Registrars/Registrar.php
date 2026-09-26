<?php

namespace App\Extensions\Registrars;

use App\Contracts\DomainRegistrar;
use App\Extensions\ExtensionManifest;
use App\Models\Domain;

/**
 * Base class for registrar extensions. Holds the manifest and the saved settings.
 */
abstract class Registrar implements DomainRegistrar
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
        return $this->manifest->name;
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

    public function transfer(Domain $domain, Contact $contact, string $eppCode): RegistrarResult
    {
        return RegistrarResult::fail(__(':registrar cannot transfer domains yet.', ['registrar' => $this->name()]));
    }

    public function sync(Domain $domain): RegistrarResult
    {
        return RegistrarResult::fail(__(':registrar cannot read domain details.', ['registrar' => $this->name()]));
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Whether the "Test mode" setting is on, for registrars with a sandbox.
     */
    protected function inTestMode(): bool
    {
        return in_array($this->setting('mode'), ['test', 'sandbox'], true);
    }
}
