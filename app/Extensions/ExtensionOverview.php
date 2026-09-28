<?php

namespace App\Extensions;

use App\Marketplace\MarketplaceClient;
use App\Models\Domain;
use App\Models\MarketplaceInstall;
use App\Models\Product;
use App\Models\Server;
use App\Models\TldPrice;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Every installed extension with what staff need to see at a glance: switched on or not, whether its
 * settings are filled in, how much it is used, and where its settings are. Shown on the Extensions page.
 */
final class ExtensionOverview
{
    public const STATE_ON = 'on';

    public const STATE_NEEDS_SETTINGS = 'needs-settings';

    public const STATE_OFF = 'off';

    /**
     * Server modules are never switched off; they are used by servers or not yet.
     */
    public const STATE_UNUSED = 'unused';

    public const ORIGIN_BUILT_IN = 'built-in';

    public const ORIGIN_MARKETPLACE = 'marketplace';

    /**
     * Copied into the extensions folder by hand, not shipped with Nuvabill or installed from the marketplace.
     */
    public const ORIGIN_MANUAL = 'manual';

    /**
     * Extensions that come with Nuvabill itself, by type.
     */
    public const BUILT_IN = [
        ExtensionManifest::TYPE_GATEWAY => ['banktransfer', 'fastpay', 'fib', 'paypal', 'stripe', 'wayl'],
        ExtensionManifest::TYPE_SERVER => ['cpanel', 'directadmin', 'plesk', 'proxmox', 'virtualizor'],
        ExtensionManifest::TYPE_REGISTRAR => ['enom', 'namecheap', 'opensrs', 'resellerclub'],
        ExtensionManifest::TYPE_ADDON => [],
    ];

    public function __construct(
        private ExtensionManager $extensions,
        private MarketplaceClient $marketplace,
    ) {}

    /**
     * @return Collection<string, array{manifest: ExtensionManifest, state: string, origin: string, removable: bool, update: string|null, unlicensed: bool, usage: string|null, page: string|null}>
     */
    public function all(): Collection
    {
        $installs = MarketplaceInstall::query()->get()->keyBy('slug');
        $catalog = collect($this->marketplace->cachedCatalog() ?? [])->keyBy('slug');
        $usage = $this->usage();

        return $this->extensions->manifests()->map(function (ExtensionManifest $manifest) use ($installs, $catalog, $usage): array {
            $install = $installs->get($manifest->slug);
            $latest = (string) ($catalog[$manifest->slug]['version'] ?? '');
            $state = $this->state($manifest, $usage);
            $origin = $this->origin($manifest, $install !== null);

            return [
                'manifest' => $manifest,
                'state' => $state,
                'origin' => $origin,
                'removable' => $origin === self::ORIGIN_MANUAL && in_array($state, [self::STATE_OFF, self::STATE_UNUSED], true) && ! $this->inUse($manifest, $usage),
                'update' => $install !== null && $latest !== '' && version_compare($latest, $manifest->version, '>') ? $latest : null,
                'unlicensed' => $install?->hasInvalidLicense() ?? false,
                'usage' => $this->describeUsage($manifest, $usage),
                'page' => $manifest->type === ExtensionManifest::TYPE_ADDON && $this->extensions->isEnabled($manifest->slug) && Route::has('admin.addons.'.$manifest->slug.'.index')
                    ? route('admin.addons.'.$manifest->slug.'.index')
                    : null,
            ];
        });
    }

    /**
     * Extensions that are switched on but miss required settings, so they do not work yet.
     */
    public function needsSettingsCount(): int
    {
        return $this->extensions->manifests()
            ->filter(fn (ExtensionManifest $manifest): bool => $manifest->type !== ExtensionManifest::TYPE_SERVER
                && $this->extensions->isEnabled($manifest->slug)
                && ! $this->isConfigured($manifest))
            ->count();
    }

    /**
     * Switched-on add-ons with their own admin page, for the sidebar.
     *
     * @return array<string, string> name => URL
     */
    public function addonPages(): array
    {
        return $this->extensions->ofType(ExtensionManifest::TYPE_ADDON)
            ->filter(fn (ExtensionManifest $manifest): bool => $this->extensions->isEnabled($manifest->slug) && Route::has('admin.addons.'.$manifest->slug.'.index'))
            ->mapWithKeys(fn (ExtensionManifest $manifest): array => [$manifest->name => route('admin.addons.'.$manifest->slug.'.index')])
            ->all();
    }

    public function isConfigured(ExtensionManifest $manifest): bool
    {
        try {
            return match ($manifest->type) {
                ExtensionManifest::TYPE_GATEWAY => $this->extensions->gateway($manifest->slug)->isConfigured(),
                ExtensionManifest::TYPE_REGISTRAR => $this->extensions->registrar($manifest->slug)->isConfigured(),
                ExtensionManifest::TYPE_ADDON => $this->extensions->addon($manifest->slug)->isConfigured(),
                default => true,
            };
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    public function origin(ExtensionManifest $manifest, bool $fromMarketplace): string
    {
        return match (true) {
            $fromMarketplace => self::ORIGIN_MARKETPLACE,
            in_array($manifest->slug, self::BUILT_IN[$manifest->type] ?? [], true) => self::ORIGIN_BUILT_IN,
            default => self::ORIGIN_MANUAL,
        };
    }

    /**
     * An extension added by hand that nothing needs any more, so it can be moved to quarantine: switched
     * off, and no server, domain price or domain uses it.
     */
    public function isRemovable(ExtensionManifest $manifest): bool
    {
        if ($this->origin($manifest, MarketplaceInstall::query()->where('slug', $manifest->slug)->exists()) !== self::ORIGIN_MANUAL) {
            return false;
        }

        return in_array($this->state($manifest, $usage = $this->usage()), [self::STATE_OFF, self::STATE_UNUSED], true)
            && ! $this->inUse($manifest, $usage);
    }

    /**
     * @param  array{payments: array<string, int>, servers: array<string, array{servers: int, accounts: int}>, domains: array<string, int>}  $usage
     */
    private function inUse(ExtensionManifest $manifest, array $usage): bool
    {
        return match ($manifest->type) {
            ExtensionManifest::TYPE_SERVER => isset($usage['servers'][$manifest->slug]) || Product::query()->where('server_module', $manifest->slug)->exists(),
            ExtensionManifest::TYPE_REGISTRAR => ($usage['domains'][$manifest->slug] ?? 0) > 0 || TldPrice::query()->where('registrar', $manifest->slug)->exists(),
            default => false,
        };
    }

    /**
     * @param  array{payments: array<string, int>, servers: array<string, array{servers: int, accounts: int}>, domains: array<string, int>}  $usage
     */
    private function state(ExtensionManifest $manifest, array $usage): string
    {
        if ($manifest->type === ExtensionManifest::TYPE_SERVER) {
            return isset($usage['servers'][$manifest->slug]) ? self::STATE_ON : self::STATE_UNUSED;
        }

        if (! $this->extensions->isEnabled($manifest->slug)) {
            return self::STATE_OFF;
        }

        return $this->isConfigured($manifest) ? self::STATE_ON : self::STATE_NEEDS_SETTINGS;
    }

    /**
     * @param  array{payments: array<string, int>, servers: array<string, array{servers: int, accounts: int}>, domains: array<string, int>}  $usage
     */
    private function describeUsage(ExtensionManifest $manifest, array $usage): ?string
    {
        return match ($manifest->type) {
            ExtensionManifest::TYPE_GATEWAY => __('Payments in the last 30 days: :count', ['count' => number_format($usage['payments'][$manifest->slug] ?? 0)]),
            ExtensionManifest::TYPE_SERVER => isset($usage['servers'][$manifest->slug])
                ? __('Servers: :servers · Accounts: :accounts', ['servers' => $usage['servers'][$manifest->slug]['servers'], 'accounts' => number_format($usage['servers'][$manifest->slug]['accounts'])])
                : __('No server uses it yet'),
            ExtensionManifest::TYPE_REGISTRAR => __('Domains: :count', ['count' => number_format($usage['domains'][$manifest->slug] ?? 0)]),
            default => null,
        };
    }

    /**
     * @return array{payments: array<string, int>, servers: array<string, array{servers: int, accounts: int}>, domains: array<string, int>}
     */
    private function usage(): array
    {
        $servers = [];

        foreach (Server::query()->withCount(['services as accounts_count' => fn ($query) => $query->whereIn('status', ['active', 'suspended', 'pending'])])->get(['id', 'module']) as $server) {
            $servers[$server->module]['servers'] = ($servers[$server->module]['servers'] ?? 0) + 1;
            $servers[$server->module]['accounts'] = ($servers[$server->module]['accounts'] ?? 0) + $server->accounts_count;
        }

        return [
            'payments' => Transaction::query()->where('type', 'payment')->where('paid_at', '>=', now()->subDays(30))
                ->groupBy('gateway')->selectRaw('gateway, COUNT(*) as total')->pluck('total', 'gateway')->map(fn (mixed $total): int => (int) $total)->all(),
            'servers' => $servers,
            'domains' => Domain::query()->whereNotNull('registrar')->groupBy('registrar')->selectRaw('registrar, COUNT(*) as total')->pluck('total', 'registrar')->map(fn (mixed $total): int => (int) $total)->all(),
        ];
    }
}
