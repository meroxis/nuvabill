<?php

namespace App\Extensions\Servers;

use App\Contracts\ServerModule;
use App\Extensions\ExtensionManifest;
use App\Models\Service;

/**
 * Base class for server module extensions.
 */
abstract class Module implements ServerModule
{
    public function __construct(protected ExtensionManifest $manifest) {}

    public function slug(): string
    {
        return $this->manifest->slug;
    }

    public function name(): string
    {
        return $this->manifest->name;
    }

    public function productFields(): array
    {
        return [];
    }

    public function serverHelp(): string
    {
        return '';
    }

    public function changePackage(Service $service): ModuleResult
    {
        return ModuleResult::fail(__('This module cannot change packages.'));
    }

    public function loginUrl(Service $service): ?string
    {
        return null;
    }

    /**
     * Whether clients see an "Open control panel" button. Modules without a panel login turn it off.
     */
    public function hasLoginLink(): bool
    {
        return true;
    }

    /**
     * A value this module saved on the service when it was created, for example the VPS ID.
     */
    protected function moduleValue(Service $service, string $key, mixed $default = null): mixed
    {
        return $service->module_data[$key] ?? $default;
    }

    /**
     * A product setting for the service's product, for example the package name.
     */
    protected function productSetting(Service $service, string $key, mixed $default = null): mixed
    {
        return $service->product->module_config[$key] ?? $default;
    }
}
