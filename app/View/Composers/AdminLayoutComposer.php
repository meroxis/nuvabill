<?php

namespace App\View\Composers;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Marketplace\LicenseChecker;
use App\Marketplace\MarketplaceClient;
use App\Models\MarketplaceInstall;
use App\Models\Order;
use App\Models\Ticket;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Supplies the badge counts shown in the admin sidebar, and warnings about unlicensed packages.
 */
class AdminLayoutComposer
{
    public function __construct(
        private MarketplaceClient $marketplace,
        private LicenseChecker $licenses,
    ) {}

    public function compose(View $view): void
    {
        $latest = setting('updates.latest');
        $installs = MarketplaceInstall::query()->get(['slug', 'name', 'version', 'license_status', 'license_message']);

        $view->with([
            'pendingOrders' => Order::query()->where('status', OrderStatus::Pending)->count(),
            'ticketsAwaitingReply' => Ticket::query()->whereIn('status', [TicketStatus::Open, TicketStatus::CustomerReply])->count(),
            'updateAvailable' => is_array($latest)
                && isset($latest['version'])
                && version_compare((string) $latest['version'], (string) config('nuvabill.version'), '>'),
            'marketplaceUpdates' => $this->marketplaceUpdates($installs),
            'licenseWarnings' => $installs->toBase()->filter(fn (MarketplaceInstall $install): bool => $install->hasInvalidLicense())
                ->map(fn (MarketplaceInstall $install): array => ['slug' => $install->slug, 'name' => $install->name, 'message' => (string) $install->license_message])
                ->merge(collect($this->licenses->cachedUnlicensedCopies())->map(fn (array $copy): array => $copy + ['message' => __('This paid copy was not installed from the marketplace, so it has no license.')]))
                ->values(),
        ]);
    }

    /**
     * Updates found in the cached catalog. The store is never called while rendering a page.
     *
     * @param  Collection<int, MarketplaceInstall>  $installs
     */
    private function marketplaceUpdates($installs): int
    {
        $catalog = collect($this->marketplace->cachedCatalog() ?? [])->keyBy('slug');

        return $installs->filter(fn (MarketplaceInstall $install): bool => isset($catalog[$install->slug]['version'])
            && version_compare((string) $catalog[$install->slug]['version'], $install->version, '>'))->count();
    }
}
