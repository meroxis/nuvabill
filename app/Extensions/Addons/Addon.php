<?php

namespace App\Extensions\Addons;

use App\Events\OrderPlaced;
use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Base class for add-on extensions: features that are not a gateway, server module or registrar,
 * for example chat alerts or a live chat widget.
 *
 * An add-on is booted on every request while it is switched on. In boot() it can listen to
 * events such as {@see OrderPlaced}, and it can add HTML to page heads and extra
 * sources to the content security policy. Since Nuvabill 0.4.2 it can also run on a schedule,
 * add admin pages and show a panel on its settings page.
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
     * Fields shown on the add-on's settings page, in the same format as gateway settings.
     *
     * @return array<string, array{label: string, type: string, help?: string, required?: bool, options?: array<string, string>}>
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
     * List "admin-page" in the manifest's permissions.
     */
    public function adminRoutes(): void {}

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
     *
     * @param  array<string, mixed>  $values
     */
    protected function remember(array $values): void
    {
        $extensions = app(ExtensionManager::class);
        $this->settings = array_merge($extensions->settings($this->slug()), $values);
        $extensions->saveSettings($this->slug(), $this->settings, $extensions->isEnabled($this->slug()));
    }
}
