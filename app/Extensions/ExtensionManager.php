<?php

namespace App\Extensions;

use App\Contracts\DomainRegistrar;
use App\Contracts\PaymentGateway;
use App\Contracts\ServerModule;
use App\Extensions\Addons\Addon;
use App\Models\Extension;
use App\Support\Installation;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
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

    /**
     * @var Collection<string, Addon>|null
     */
    private ?Collection $activeAddons = null;

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
        $this->activeAddons = null;
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
                && ($currency === null || $gateway->chargeCurrencyFor($currency) !== null));
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

    public function addon(string $slug): Addon
    {
        $manifest = $this->find($slug);

        if ($manifest === null || $manifest->type !== ExtensionManifest::TYPE_ADDON) {
            throw new InvalidArgumentException("Add-on [{$slug}] was not found.");
        }

        $addon = new ($manifest->class)($manifest, $this->settings($slug));

        if (! $addon instanceof Addon) {
            throw new InvalidArgumentException("[{$manifest->class}] must extend ".Addon::class.'.');
        }

        return $addon;
    }

    /**
     * Add-ons that are switched on and fully set up, keyed by slug. Made once per request.
     *
     * @return Collection<string, Addon>
     */
    public function activeAddons(): Collection
    {
        return $this->activeAddons ??= $this->ofType(ExtensionManifest::TYPE_ADDON)
            ->filter(fn (ExtensionManifest $manifest): bool => $this->isEnabled($manifest->slug))
            ->map(fn (ExtensionManifest $manifest): ?Addon => $this->tryMake(fn () => $this->addon($manifest->slug)))
            ->filter(fn (?Addon $addon): bool => $addon !== null && $addon->isConfigured());
    }

    /**
     * Let every active add-on register its listeners. A broken add-on is reported and skipped.
     */
    public function bootAddons(): void
    {
        foreach ($this->activeAddons() as $addon) {
            $this->tryMake(function () use ($addon): bool {
                $addon->boot();

                return true;
            });
        }
    }

    /**
     * The HTML every active add-on adds to the <head> of pages in an area ("client" or "admin").
     */
    public function headHtml(string $area): string
    {
        return $this->activeAddons()
            ->map(fn (Addon $addon): string => (string) $this->tryMake(fn (): string => $addon->headHtml($area)))
            ->filter()
            ->implode("\n");
    }

    /**
     * Content security policy sources asked for by active add-ons, merged by directive.
     *
     * @return array<string, list<string>>
     */
    public function contentSecurityPolicy(): array
    {
        $sources = [];

        foreach ($this->activeAddons() as $addon) {
            foreach ((array) $this->tryMake(fn (): array => $addon->contentSecurityPolicy()) as $directive => $values) {
                foreach ((array) $values as $value) {
                    // Only plain host sources: no quotes, spaces or semicolons that could change the policy.
                    if (is_string($value) && preg_match('#^(https|wss)://[a-z0-9.*-]+(:\d+)?(/[\w./-]*)?$#i', $value)) {
                        $sources[$directive][] = $value;
                    }
                }
            }
        }

        return array_map(fn (array $values): array => array_values(array_unique($values)), $sources);
    }

    /**
     * Extensions with a views folder can ship Blade views, used as "ext-{slug}::name", and a lang
     * folder with JSON translations (lang/ar.json, lang/ckb.json) for their own texts.
     */
    public function registerViews(): void
    {
        foreach ($this->manifests() as $manifest) {
            $views = $manifest->path.DIRECTORY_SEPARATOR.'views';

            if (is_dir($views)) {
                View::addNamespace('ext-'.$manifest->slug, $views);
            }

            $lang = $manifest->path.DIRECTORY_SEPARATOR.'lang';

            if (is_dir($lang)) {
                Lang::addJsonPath($lang);
            }
        }
    }

    /**
     * Let every active add-on add its scheduled work. A broken add-on is reported and skipped.
     */
    public function scheduleAddons(Schedule $schedule): void
    {
        foreach ($this->activeAddons() as $addon) {
            $this->tryMake(function () use ($addon, $schedule): bool {
                $addon->schedule($schedule);

                return true;
            });
        }
    }

    /**
     * Admin pages of active add-ons, under /admin/addons/{slug}/. Call inside the admin route group.
     */
    public function registerAddonRoutes(): void
    {
        foreach ($this->activeAddons() as $slug => $addon) {
            $this->tryMake(function () use ($slug, $addon): bool {
                Route::prefix($slug)->name($slug.'.')->group(fn () => $addon->adminRoutes());

                return true;
            });
        }
    }

    /**
     * Forget cached manifests and database records, for example after installing an extension.
     */
    public function refresh(): void
    {
        $this->manifests = null;
        $this->records = null;
        $this->activeAddons = null;
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
