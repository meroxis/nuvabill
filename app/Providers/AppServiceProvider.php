<?php

namespace App\Providers;

use App\Domains\Rdap;
use App\Extensions\ExtensionManager;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Support\Demo;
use App\Support\Installation;
use App\Support\ServicePanels;
use App\Support\Settings;
use App\Support\Themes;
use App\View\Composers\AdminLayoutComposer;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        Installation::ensureAppKey();

        $this->app->singleton(Settings::class);
        $this->app->singleton(ServicePanels::class);
        $this->app->singleton(Themes::class, fn (): Themes => new Themes(config('nuvabill.themes_path'), config('nuvabill.orderforms_path')));
        $this->app->singleton(ExtensionManager::class, fn (): ExtensionManager => new ExtensionManager(config('nuvabill.extensions_path')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::enforceMorphMap([
            'admin' => Admin::class,
            'client' => Client::class,
            'domain' => Domain::class,
            'invoice' => Invoice::class,
            'order' => Order::class,
            'product' => Product::class,
            'server' => Server::class,
            'service' => Service::class,
            'ticket' => Ticket::class,
            'transaction' => Transaction::class,
            'activity' => ActivityLog::class,
        ]);

        Paginator::defaultView('components.pagination');
        View::composer('components.layouts.admin', AdminLayoutComposer::class);

        ResetPassword::createUrlUsing(fn (Admin|Client $user, string $token): string => $user instanceof Admin
            ? route('admin.password.reset', ['token' => $token, 'email' => $user->email])
            : route('client.password.reset', ['token' => $token, 'email' => $user->email]));

        $this->app->make(Themes::class)->register();
        $this->app->make(ExtensionManager::class)->registerViews();

        if (Installation::isInstalled()) {
            $this->applySettingsToConfig();
            $this->app->make(ExtensionManager::class)->bootAddons();
        }

        if (Demo::isEnabled()) {
            // The demo never sends email or calls payment, server or update APIs. Free RDAP lookups for domain search
            // and reading the marketplace catalog are allowed.
            config(['mail.default' => 'log']);
            Http::preventStrayRequests();
            Http::allowStrayRequests([Rdap::BOOTSTRAP_URL, 'https://rdap.*', 'https://*.rdap.*', 'https://*/rdap/*', config('nuvabill.marketplace.url').'/api/marketplace/v1/catalog*']);
            Demo::fakeServers();
        }
    }

    /**
     * Company name and outgoing mail are edited in the admin area, so copy them into Laravel's config.
     */
    private function applySettingsToConfig(): void
    {
        $settings = $this->app->make(Settings::class);

        config(['app.name' => $settings->get('company.name')]);

        if ($this->app->runningUnitTests()) {
            return;
        }

        config([
            'mail.default' => $settings->get('mail.mailer'),
            'mail.mailers.smtp.host' => $settings->get('mail.host'),
            'mail.mailers.smtp.port' => (int) $settings->get('mail.port'),
            'mail.mailers.smtp.username' => $settings->get('mail.username'),
            'mail.mailers.smtp.password' => $settings->get('mail.password'),
            'mail.mailers.smtp.scheme' => $settings->get('mail.encryption') === 'ssl' ? 'smtps' : null,
            'mail.from.address' => $settings->get('mail.from_address'),
            'mail.from.name' => $settings->get('mail.from_name'),
        ]);
    }
}
