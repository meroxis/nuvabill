<?php

namespace App\Providers;

use App\Ai\TicketAssistant;
use App\Automations\Registry;
use App\Billing\Affiliates;
use App\Domains\Rdap;
use App\Events\ClientRegistered;
use App\Events\InvoicePaid;
use App\Events\OrderPlaced;
use App\Events\ServiceActivated;
use App\Events\ServiceSuspended;
use App\Events\ServiceTerminated;
use App\Events\TicketOpened;
use App\Events\TicketReplied;
use App\Extensions\ExtensionManager;
use App\Jobs\TranslateTicketReply;
use App\Models\ActivityLog;
use App\Models\Admin;
use App\Models\Automation;
use App\Models\Client;
use App\Models\Domain;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Quote;
use App\Models\Server;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\Transaction;
use App\Push\StaffAlerts;
use App\Seo\Seo;
use App\Support\Demo;
use App\Support\Installation;
use App\Support\ServicePanels;
use App\Support\Settings;
use App\Support\Themes;
use App\View\Composers\AdminLayoutComposer;
use Closure;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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
        $this->app->scoped(Seo::class);
        $this->app->singleton(Registry::class);
        $this->app->singleton(Themes::class, fn (): Themes => new Themes(config('nuvabill.themes_path'), config('nuvabill.orderforms_path')));
        $this->app->singleton(ExtensionManager::class, fn (): ExtensionManager => new ExtensionManager(config('nuvabill.extensions_path')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Behind a proxy that talks plain HTTP to this server, links would otherwise start with http://.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Relation::enforceMorphMap([
            'admin' => Admin::class,
            'automation' => Automation::class,
            'client' => Client::class,
            'domain' => Domain::class,
            'invoice' => Invoice::class,
            'order' => Order::class,
            'product' => Product::class,
            'quote' => Quote::class,
            'server' => Server::class,
            'service' => Service::class,
            'ticket' => Ticket::class,
            'transaction' => Transaction::class,
            'activity' => ActivityLog::class,
        ]);

        Paginator::defaultView('components.pagination');
        View::composer('components.layouts.admin', AdminLayoutComposer::class);
        // The client area page title as the theme shows it, for the search engine title pattern.
        View::composer('theme::layouts.*', fn (\Illuminate\View\View $view) => $this->app->make(Seo::class)->capturePageTitle($view->getFactory()->yieldContent('title')));

        ResetPassword::createUrlUsing(fn (Admin|Client $user, string $token): string => $user instanceof Admin
            ? route('admin.password.reset', ['token' => $token, 'email' => $user->email])
            : route('client.password.reset', ['token' => $token, 'email' => $user->email]));

        $this->app->make(Themes::class)->register();
        $this->app->make(ExtensionManager::class)->registerViews();

        RateLimiter::for('api-v1', fn (Request $request): Limit => Limit::perMinute(120)->by($request->bearerToken() ? hash('sha256', $request->bearerToken()) : (string) $request->ip()));

        if (Installation::isInstalled()) {
            $this->applySettingsToConfig();
            $this->app->make(ExtensionManager::class)->bootAddons();
            // A problem with an affiliate commission must never stop a payment.
            Event::listen(InvoicePaid::class, fn (InvoicePaid $event) => rescue(fn () => $this->app->make(Affiliates::class)->onInvoicePaid($event->invoice)));
            $this->listenForAutomations();
            $this->listenForStaffAlerts();
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
     * Start automations when something happens. Registry::fire() never throws and waits for the
     * database transaction around the event to finish.
     */
    private function listenForAutomations(): void
    {
        $fire = fn (string $trigger, Model $subject, string $occurrence = '') => $this->app->make(Registry::class)->fire($trigger, $subject, $occurrence);

        Event::listen(ClientRegistered::class, fn (ClientRegistered $event) => $fire('client.registered', $event->client));
        Event::listen(OrderPlaced::class, fn (OrderPlaced $event) => $fire('order.placed', $event->order));
        Event::listen(InvoicePaid::class, fn (InvoicePaid $event) => $fire('invoice.paid', $event->invoice));
        Event::listen(ServiceActivated::class, fn (ServiceActivated $event) => $fire('service.activated', $event->service));
        Event::listen(ServiceSuspended::class, fn (ServiceSuspended $event) => $fire('service.suspended', $event->service, 's'.$event->service->suspended_at?->timestamp));
        Event::listen(ServiceTerminated::class, fn (ServiceTerminated $event) => $fire('service.terminated', $event->service));
        Event::listen(TicketOpened::class, fn (TicketOpened $event) => $fire('ticket.opened', $event->ticket));
        Event::listen(TicketReplied::class, fn (TicketReplied $event) => $event->reply->author_type === 'client'
            ? $fire('ticket.client_reply', $event->ticket, 'r'.$event->reply->id)
            : null);

        // AI help: translate new client messages for staff in the background.
        $translate = fn (TicketReply $reply) => rescue(function () use ($reply): void {
            if ($this->app->make(TicketAssistant::class)->mightNeedTranslation($reply)) {
                TranslateTicketReply::dispatch($reply->id)->afterCommit();
            }
        });
        Event::listen(TicketOpened::class, fn (TicketOpened $event) => $translate($event->message));
        Event::listen(TicketReplied::class, fn (TicketReplied $event) => $translate($event->reply));
    }

    /**
     * Admin phone app: push alerts on staff phones. A problem with an alert never stops an order,
     * a payment or a ticket.
     */
    private function listenForStaffAlerts(): void
    {
        $alerts = fn (Closure $send) => rescue(fn () => $send($this->app->make(StaffAlerts::class)), report: false);

        Event::listen(OrderPlaced::class, fn (OrderPlaced $event) => $alerts(fn (StaffAlerts $staff) => $staff->orderPlaced($event->order)));
        Event::listen(InvoicePaid::class, fn (InvoicePaid $event) => $alerts(fn (StaffAlerts $staff) => $staff->invoicePaid($event->invoice)));
        Event::listen(TicketOpened::class, fn (TicketOpened $event) => $alerts(fn (StaffAlerts $staff) => $staff->ticketOpened($event->ticket)));
        Event::listen(TicketReplied::class, fn (TicketReplied $event) => $event->reply->author_type === 'client'
            ? $alerts(fn (StaffAlerts $staff) => $staff->clientReplied($event->ticket, $event->reply))
            : null);
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
