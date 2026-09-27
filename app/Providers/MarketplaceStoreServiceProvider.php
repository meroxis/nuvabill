<?php

namespace App\Providers;

use App\Events\InvoicePaid;
use App\Events\ServiceActivated;
use App\Marketplace\Store\EarningsRecorder;
use App\Marketplace\Store\LicenseService;
use App\Models\License;
use App\Support\ServicePanels;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Turns this copy of Nuvabill into the marketplace store (NUVABILL_MARKETPLACE_STORE=true, only on
 * my.nuvabill.com): the catalog API, public marketplace pages, the developer portal, reviews,
 * license keys for purchases, developer earnings and payouts. Other copies skip all of this.
 */
class MarketplaceStoreServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (config('nuvabill.marketplace.store')) {
            $this->enable();
        }
    }

    /**
     * Register the store's listeners, service page panel and routes.
     */
    public function enable(): void
    {
        Event::listen(ServiceActivated::class, fn (ServiceActivated $event) => $this->app->make(LicenseService::class)->issueFor($event->service));
        Event::listen(InvoicePaid::class, fn (InvoicePaid $event) => $this->app->make(EarningsRecorder::class)->record($event->invoice));

        $this->app->make(ServicePanels::class)->add(function ($service): ?array {
            $license = License::query()->with('item')->where('service_id', $service->id)->first();

            return $license === null ? null : ['view' => 'theme::developer.license-panel', 'data' => ['license' => $license]];
        });

        $this->loadRoutesFrom(base_path('routes/store-api.php'));
        $this->loadRoutesFrom(base_path('routes/store.php'));

        // Routes named after they were added are only found once the lookups are rebuilt.
        $this->app->booted(function (): void {
            $this->app['router']->getRoutes()->refreshNameLookups();
            $this->app['router']->getRoutes()->refreshActionLookups();
        });
    }
}
