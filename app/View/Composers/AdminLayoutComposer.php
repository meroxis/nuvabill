<?php

namespace App\View\Composers;

use App\Enums\OrderStatus;
use App\Enums\TicketStatus;
use App\Extensions\ExtensionOverview;
use App\Marketplace\LicenseChecker;
use App\Marketplace\MarketplaceClient;
use App\Models\HealthRun;
use App\Models\MarketplaceInstall;
use App\Models\Order;
use App\Models\Ticket;
use App\Support\Demo;
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
        private ExtensionOverview $extensions,
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
            // Only the count: the results of a run are large and not needed on every page. Right
            // after an update the table may not exist for a minute, until its database change runs.
            'extensionsNeedSettings' => (int) rescue(fn () => $this->extensions->needsSettingsCount(), 0),
            'addonPages' => (array) rescue(fn () => $this->extensions->addonPages(), []),
            'healthUrgent' => (int) rescue(fn () => HealthRun::query()->latest('id')->value('urgent_count'), 0, report: false),
            'licenseWarnings' => $installs->toBase()->filter(fn (MarketplaceInstall $install): bool => $install->hasInvalidLicense())
                ->map(fn (MarketplaceInstall $install): array => ['slug' => $install->slug, 'name' => $install->name, 'message' => (string) $install->license_message])
                // The public demo shows paid themes for previews on purpose; it resets every hour and cannot be used as a real site.
                ->merge(Demo::isEnabled() ? [] : collect($this->licenses->cachedUnlicensedCopies())->map(fn (array $copy): array => $copy + ['message' => __('This paid copy was not installed from the marketplace, so it has no license.')]))
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
