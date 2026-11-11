<?php

namespace App\Extensions\Addons;

use App\Events\OrderPlaced;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Base class for add-on extensions: features that are not a gateway, server module or registrar,
 * for example chat alerts or a live chat widget.
 *
 * An add-on is booted on every request while it is switched on. In boot() it can listen to
 * events such as {@see OrderPlaced}, and it can add HTML to page heads and extra
 * sources to the content security policy. Since Nuvabill 0.4.2 it can also run on a schedule,
 * add admin pages and show a panel on its settings page. Since 0.6.1 it can show pages to visitors,
 * the home page included, and list them in sitemap.xml. Since 0.7.0 it can hook into dedicated
 * servers (Admin → Hardware): see docs/hardware.md and hardwareRoutes().
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
     * Fields shown on the add-on's settings page, in the same format as gateway settings. A field
     * with 'owner' => true can only be changed by staff with the Owner role (since Nuvabill 0.7.3):
     * use it for settings that decide where data goes, such as a backup password or a connection key.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>, owner?: bool}>
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
     * Settings for a public demo site that switches this add-on on after each reset
     * (NUVABILL_DEMO_ADDONS), for example options its demo data needs. Visitors can read them,
     * so never put a secret here. Without it the add-on is switched on with no settings, so each
     * one has its default. Since Nuvabill 0.7.1.
     *
     * @return array<string, mixed>
     */
    public function demoSettings(): array
    {
        return [];
    }

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

    /**
     * Scheduled work, run by the site's cron job. Give closures a name, for example
     * $schedule->call(fn () => $this->sync())->hourly()->name('my-addon-sync')->withoutOverlapping().
     * List "schedule" in the manifest's permissions.
     */
    public function schedule(Schedule $schedule): void {}

    /**
     * Admin pages, registered under /admin/addons/{slug}/ for staff who may manage the marketplace.
     * Give them short names, for example Route::get('connect', ...)->name('connect'), and link to them
     * with route($this->routeName('connect')).
     * List "admin-page" in the manifest's permissions. That permission alone must never be enough to
     * send site data somewhere new: check for an owner in such a page (see docs/developers/addons.md).
     * The pages of an add-on that lists "backups" are for owners only (since Nuvabill 0.7.3).
     */
    public function adminRoutes(): void {}

    /**
     * Pages for visitors, registered after all of Nuvabill's own pages, so they can never replace one.
     * Give them short names, for example Route::get('about', ...)->name('about'), and link to them with
     * route($this->publicRouteName('about')). Search engines only show them when the page calls
     * allowIndexing() on App\Seo\Seo. List "public-page" in the manifest's permissions.
     */
    public function publicRoutes(): void {}

    /**
     * Addresses another service sends data to, such as a chat bridge reporting new messages. They are
     * registered under /webhooks/addons/{slug}/, without a session, cookies or a form token, so check
     * every request yourself: put a long random secret in the address or verify a signature. Link to
     * them with route($this->webhookRouteName('messages')). List "webhook" in the manifest's
     * permissions. Since Nuvabill 0.6.10.
     */
    public function webhookRoutes(): void {}

    /**
     * Pages and form targets for the Hardware area, registered under /admin/hardware/addons/{slug}/
     * for staff who may manage dedicated server hardware (hardware.manage), with the admin area's
     * sign-in, two-factor and form-token checks. For example the form that saves a machine's switch
     * port. Give them short names and link to them with route($this->hardwareRouteName('port')).
     * List "hardware" in the manifest's permissions. Since Nuvabill 0.7.0.
     */
    public function hardwareRoutes(): void {}

    /**
     * The full route name for one of this add-on's Hardware pages.
     */
    public function hardwareRouteName(string $name): string
    {
        return 'admin.hardware-addons.'.$this->slug().'.'.$name;
    }

    /**
     * Whether this add-on shows the site's home page instead of the store. The store then moves to /store.
     * Needs "public-page" in the manifest's permissions.
     */
    public function servesHomePage(): bool
    {
        return false;
    }

    /**
     * The home page, when servesHomePage() says so.
     */
    public function homePage(Request $request): mixed
    {
        abort(404);
    }

    /**
     * The add-on's public pages for sitemap.xml: paths without the first slash, and when each last changed.
     *
     * @return list<array{path: string, updated: Carbon|null}>
     */
    public function sitemapPages(): array
    {
        return [];
    }

    /**
     * The full route name for one of this add-on's public pages.
     */
    public function publicRouteName(string $name): string
    {
        return 'addon.'.$this->slug().'.'.$name;
    }

    /**
     * The full route name for one of this add-on's webhook addresses.
     */
    public function webhookRouteName(string $name): string
    {
        return 'webhooks.addon.'.$this->slug().'.'.$name;
    }

    /**
     * HTML shown under the settings form, for example a connection status and buttons.
     */
    public function settingsHtml(): string
    {
        return '';
    }

    /**
     * The full route name for one of this add-on's admin pages.
     */
    public function routeName(string $name): string
    {
        return 'admin.addons.'.$this->slug().'.'.$name;
    }

    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    /**
     * Save values the add-on keeps for itself, such as a connection token or the time of the last run.
     * They are stored encrypted with the add-on's settings and survive saving the settings form.
     * Only these values change: settings staff saved meanwhile are kept.
     *
     * @param  array<string, mixed>  $values
     */
    protected function remember(array $values): void
    {
        $this->settings = app(ExtensionManager::class)->mergeSettings($this->slug(), $values);
    }
}
