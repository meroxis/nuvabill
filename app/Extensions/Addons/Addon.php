<?php

namespace App\Extensions\Addons;

use App\Events\OrderPlaced;
use App\Extensions\ExtensionManifest;

/**
 * Base class for add-on extensions: features that are not a gateway, server module or registrar,
 * for example chat alerts or a live chat widget.
 *
 * An add-on is booted on every request while it is switched on. In boot() it can listen to
 * events such as {@see OrderPlaced}, and it can add HTML to page heads and extra
 * sources to the content security policy.
 */
abstract class Addon
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

    /**
     * Fields shown on the add-on's settings page, in the same format as gateway settings.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>}>
     */
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

    /**
     * Register event listeners and anything else the add-on needs. Runs once per request.
     */
    public function boot(): void {}

    /**
     * HTML added to the end of the <head> of pages in an area: "client" (store and client area) or "admin".
     */
    public function headHtml(string $area): string
    {
        return '';
    }

    /**
     * Extra sources for the content security policy, for example
     * ['script-src' => ['https://embed.example.com'], 'connect-src' => ['wss://*.example.com']].
     *
     * @return array<string, list<string>>
     */
    public function contentSecurityPolicy(): array
    {
        return [];
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
