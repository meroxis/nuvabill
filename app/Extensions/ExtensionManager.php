<?php

namespace App\Extensions;

use App\Contracts\DomainRegistrar;
use App\Contracts\PaymentGateway;
use App\Contracts\ServerModule;
use App\Models\Extension;
use App\Support\Installation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Finds extensions in the extensions folder, loads their classes and creates gateway and module instances.
 *
 * Layout: extensions/{gateways|servers|registrars}/{slug}/extension.json with PHP classes in src/.
 */
class ExtensionManager
{
    /**
     * @var Collection<string, ExtensionManifest>|null
     */
    private ?Collection $manifests = null;

    /**
     * @var array<string, string> Namespace prefix => src directory.
     */
    private array $autoloadMap = [];

    private bool $autoloaderRegistered = false;

    /**
     * @var Collection<string, Extension>|null
     */
    private ?Collection $records = null;

    public function __construct(private string $path) {}

    /**
     * Every valid extension found on disk, keyed by slug.
     *
     * @return Collection<string, ExtensionManifest>
     */
    public function manifests(): Collection
    {
        if ($this->manifests !== null) {
            return $this->manifests;
        }

        $manifests = collect();

        foreach (glob($this->path.'/*/*/extension.json') ?: [] as $file) {
            try {
                $manifest = ExtensionManifest::fromFile($file);
            } catch (InvalidArgumentException $exception) {
                Log::warning($exception->getMessage());

                continue;
            }

            if (! $manifest->isCompatibleWith(config('nuvabill.version'))) {
                Log::warning("Extension [{$manifest->slug}] needs Nuvabill {$manifest->requires} and was skipped.");

                continue;
            }

            $manifests->put($manifest->slug, $manifest);
            $this->autoloadMap[$manifest->namespace] = $manifest->path.DIRECTORY_SEPARATOR.'src';
        }

        $this->registerAutoloader();

        return $this->manifests = $manifests->sortBy('name');
    }

    /**
     * @return Collection<string, ExtensionManifest>
     */
    public function ofType(string $type): Collection
    {
        return $this->manifests()->where('type', $type);
    }

    public function find(string $slug): ?ExtensionManifest
    {
        return $this->manifests()->get($slug);
    }

    public function record(string $slug): Extension
    {
        $manifest = $this->find($slug) ?? throw new InvalidArgumentException("Extension [{$slug}] was not found.");

        $record = $this->records()->get($slug);

        if ($record === null) {
            $record = Extension::create([
                'slug' => $slug,
                'type' => $manifest->type,
                'is_enabled' => false,
                'settings' => [],
                'version' => $manifest->version,
            ]);
            $this->records?->put($slug, $record);
        }

        return $record;
    }

    public function isEnabled(string $slug): bool
    {
        if ($this->find($slug) === null) {
            return false;
        }

        return $this->records()->get($slug)?->is_enabled === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(string $slug): array
    {
        return $this->records()->get($slug)?->settings ?? [];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public function saveSettings(string $slug, array $settings, bool $enabled): void
    {
        $record = $this->record($slug);
        $record->update(['settings' => $settings, 'is_enabled' => $enabled]);
        $this->records = null;
    }

    public function gateway(string $slug): PaymentGateway
    {
        $manifest = $this->find($slug);

        if ($manifest === null || $manifest->type !== ExtensionManifest::TYPE_GATEWAY) {
            throw new InvalidArgumentException("Payment gateway [{$slug}] was not found.");
        }

        $gateway = new ($manifest->class)($manifest, $this->settings($slug));

        if (! $gateway instanceof PaymentGateway) {
            throw new InvalidArgumentException("[{$manifest->class}] must implement ".PaymentGateway::class.'.');
        }

        return $gateway;
    }

    /**
     * Gateways that are switched on and fully set up, keyed by slug.
     *
     * @return Collection<string, PaymentGateway>
     */
    public function activeGateways(?string $currency = null): Collection
    {
        return $this->ofType(ExtensionManifest::TYPE_GATEWAY)
            ->filter(fn (ExtensionManifest $manifest): bool => $this->isEnabled($manifest->slug))
            ->map(fn (ExtensionManifest $manifest): ?PaymentGateway => $this->tryMake(fn () => $this->gateway($manifest->slug)))
            ->filter(fn (?PaymentGateway $gateway): bool => $gateway !== null
                && $gateway->isConfigured()
                && ($currency === null || $gateway->supportsCurrency($currency)));
    }

    public function serverModule(string $slug): ServerModule
    {
        $manifest = $this->find($slug);

        if ($manifest === null || $manifest->type !== ExtensionManifest::TYPE_SERVER) {
            throw new InvalidArgumentException("Server module [{$slug}] was not found.");
        }

        $module = new ($manifest->class)($manifest);

        if (! $module instanceof ServerModule) {
            throw new InvalidArgumentException("[{$manifest->class}] must implement ".ServerModule::class.'.');
        }

        return $module;
    }

    /**
     * Server modules keyed by slug, with their display names.
     *
     * @return Collection<string, string>
     */
    public function serverModuleNames(): Collection
    {
        return $this->ofType(ExtensionManifest::TYPE_SERVER)->map(fn (ExtensionManifest $manifest): string => $manifest->name);
    }

    public function registrar(string $slug): DomainRegistrar
    {
        $manifest = $this->find($slug);

        if ($manifest === null || $manifest->type !== ExtensionManifest::TYPE_REGISTRAR) {
            throw new InvalidArgumentException("Registrar [{$slug}] was not found.");
        }

        $registrar = new ($manifest->class)($manifest, $this->settings($slug));

        if (! $registrar instanceof DomainRegistrar) {
            throw new InvalidArgumentException("[{$manifest->class}] must implement ".DomainRegistrar::class.'.');
        }

        return $registrar;
    }

    /**
     * Registrars that are switched on and fully set up, keyed by slug.
     *
     * @return Collection<string, DomainRegistrar>
     */
    public function activeRegistrars(): Collection
    {
        return $this->ofType(ExtensionManifest::TYPE_REGISTRAR)
            ->filter(fn (ExtensionManifest $manifest): bool => $this->isEnabled($manifest->slug))
            ->map(fn (ExtensionManifest $manifest): ?DomainRegistrar => $this->tryMake(fn () => $this->registrar($manifest->slug)))
            ->filter(fn (?DomainRegistrar $registrar): bool => $registrar !== null && $registrar->isConfigured());
    }

    /**
     * Registrars keyed by slug, with their display names.
     *
     * @return Collection<string, string>
     */
    public function registrarNames(): Collection
    {
        return $this->ofType(ExtensionManifest::TYPE_REGISTRAR)->map(fn (ExtensionManifest $manifest): string => $manifest->name);
    }

    /**
     * Forget cached manifests and database records, for example after installing an extension.
     */
    public function refresh(): void
    {
        $this->manifests = null;
        $this->records = null;
    }

    /**
     * @return Collection<string, Extension>
     */
    private function records(): Collection
    {
        if ($this->records !== null) {
            return $this->records;
        }

        if (! Installation::isInstalled()) {
            return collect();
        }

        try {
            return $this->records = Extension::all()->keyBy('slug');
        } catch (QueryException) {
            return collect();
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T|null
     */
    private function tryMake(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function registerAutoloader(): void
    {
        if ($this->autoloaderRegistered) {
            return;
        }

        $this->autoloaderRegistered = true;

        spl_autoload_register(function (string $class): void {
            foreach ($this->autoloadMap as $prefix => $directory) {
                if (! str_starts_with($class, $prefix)) {
                    continue;
                }

                $file = $directory.DIRECTORY_SEPARATOR.str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))).'.php';

                if (is_file($file)) {
                    require $file;

                    return;
                }
            }
        });
    }
}
