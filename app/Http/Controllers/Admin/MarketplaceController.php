<?php

namespace App\Http\Controllers\Admin;

use App\Extensions\ExtensionManager;
use App\Extensions\ExtensionManifest;
use App\Http\Controllers\Controller;
use App\Marketplace\LicenseChecker;
use App\Marketplace\Listing;
use App\Marketplace\MarketplaceClient;
use App\Marketplace\PackageInstaller;
use App\Marketplace\PackageType;
use App\Models\MarketplaceInstall;
use App\Support\Activity;
use App\Support\Settings;
use App\Support\Themes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use RuntimeException;

/**
 * Admin → Marketplace: browse themes, order forms and extensions, and install, update or remove
 * them in one click. Paid items need the license key from the purchase.
 */
class MarketplaceController extends Controller
{
    public const TABS = ['discover', 'themes', 'orderforms', 'extensions', 'installed', 'updates'];

    public function __construct(
        private MarketplaceClient $client,
        private Themes $themes,
        private ExtensionManager $extensions,
    ) {}

    public function index(Request $request): View
    {
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'discover';
        // Ask the store for new versions whenever staff look at what they have installed.
        $catalog = $this->catalog(fresh: in_array($tab, ['installed', 'updates'], true));
        $installed = $this->installed($catalog);
        $updates = $catalog->filter(fn (Listing $listing): bool => $listing->hasUpdate())->values();

        $items = match ($tab) {
            'installed' => $installed,
            'updates' => $updates,
            'discover' => $catalog,
            default => $catalog->filter(fn (Listing $listing): bool => $listing->type->tab() === $tab),
        };

        $category = (string) $request->query('category', '');
        $price = (string) $request->query('price', '');
        $search = trim((string) $request->query('q', ''));

        $items = $items
            ->when($category !== '', fn (Collection $items) => $items->filter(fn (Listing $listing): bool => $listing->category === $category))
            ->when($price === 'free', fn (Collection $items) => $items->filter(fn (Listing $listing): bool => $listing->isFree()))
            ->when($price === 'paid', fn (Collection $items) => $items->reject(fn (Listing $listing): bool => $listing->isFree()))
            ->when($search !== '', fn (Collection $items) => $items->filter(fn (Listing $listing): bool => str_contains(mb_strtolower($listing->name.' '.$listing->summary.' '.$listing->developer), mb_strtolower($search))))
            ->values();

        return view('admin.marketplace.index', [
            'tab' => $tab,
            'items' => $items,
            'featured' => $tab === 'discover' && $category === '' && $price === '' && $search === '' ? $catalog->first(fn (Listing $listing): bool => $listing->featured) : null,
            'categories' => $catalog->pluck('category')->filter()->unique()->sort()->values(),
            'installedCount' => $installed->count(),
            'updatesCount' => $updates->count(),
            'category' => $category,
            'price' => $price,
            'search' => $search,
            'error' => $this->client->lastError(),
            'activeTheme' => $this->themes->saved(),
            'activeOrderForm' => $this->themes->savedOrderForm(),
        ]);
    }

    public function show(string $slug): View
    {
        $listing = $this->find($slug);

        return view('admin.marketplace.show', [
            'listing' => $listing,
            'isActive' => $this->isActive($listing),
            'settingsUrl' => $this->settingsUrl($listing),
            'steps' => session('install_steps', []),
        ]);
    }

    public function install(Request $request, string $slug, PackageInstaller $installer): RedirectResponse
    {
        $key = trim((string) ($request->validate(['license_key' => ['nullable', 'string', 'max:64']])['license_key'] ?? '')) ?: null;
        $install = MarketplaceInstall::query()->where('slug', $slug)->first();
        $key ??= $install?->license_key;

        try {
            $download = $this->client->download($slug, $key);
            $installed = $installer->install($download, $key);
        } catch (RuntimeException $exception) {
            return back()->withInput()->with('error', $this->refusalMessage($exception));
        }

        $this->client->catalog(fresh: true);

        return redirect()->route('admin.marketplace.show', $slug)
            ->with('status', $install ? __(':name is updated to :version.', ['name' => $installed->name, 'version' => $installed->version]) : __(':name is installed.', ['name' => $installed->name]))
            ->with('install_steps', $installer->steps());
    }

    public function destroy(string $slug, PackageInstaller $installer): RedirectResponse
    {
        $install = MarketplaceInstall::query()->where('slug', $slug)->firstOrFail();
        $installer->uninstall($install);

        return redirect()->route('admin.marketplace.index', ['tab' => 'installed'])->with('status', __(':name was removed.', ['name' => $install->name]));
    }

    /**
     * Switch a theme or order form on for everyone, or open an extension's settings.
     */
    public function activate(string $slug, Settings $settings): RedirectResponse
    {
        $listing = $this->find($slug);

        abort_unless($listing->isInstalled(), 404);

        if ($listing->type === PackageType::Theme) {
            $settings->set('theme.active', $slug);
            Activity::log('theme.activated', "Client area theme changed to {$listing->name}");

            return back()->with('status', __(':name is now your client area theme.', ['name' => $listing->name]));
        }

        if ($listing->type === PackageType::OrderForm) {
            $settings->set('orderform.active', $slug);
            Activity::log('orderform.activated', "Order form changed to {$listing->name}");

            return back()->with('status', __(':name is now your order form.', ['name' => $listing->name]));
        }

        return redirect($this->settingsUrl($listing) ?? route('admin.marketplace.show', $slug));
    }

    public function deactivate(string $slug, Settings $settings): RedirectResponse
    {
        if ($this->themes->savedOrderForm() === $slug) {
            $settings->set('orderform.active', Themes::STANDARD_ORDER_FORM);
        }

        if ($this->themes->saved() === $slug) {
            $settings->set('theme.active', Themes::DEFAULT);
        }

        return back()->with('status', __('Switched back to the standard one.'));
    }

    /**
     * Save a license key for an installed item and check it with the store now.
     */
    public function license(Request $request, string $slug, LicenseChecker $checker): RedirectResponse
    {
        $install = MarketplaceInstall::query()->where('slug', $slug)->firstOrFail();
        $install->update(['license_key' => trim((string) $request->validate(['license_key' => ['required', 'string', 'max:64']])['license_key'])]);

        $checker->check($install);

        return back()->with($install->hasInvalidLicense() ? 'error' : 'status', $install->hasInvalidLicense()
            ? ($install->license_message ?: __('This license key is not valid for this site.'))
            : __('License key saved.'));
    }

    public function settings(string $slug): View
    {
        $manifest = $this->addonManifest($slug);

        $addon = $this->extensions->addon($slug);

        return view('admin.marketplace.settings', [
            'manifest' => $manifest,
            'fields' => $addon->settingsFields(),
            'panel' => $this->extensions->isEnabled($slug) ? (string) rescue(fn (): string => $addon->settingsHtml(), '') : '',
            'values' => $this->extensions->settings($slug),
            'enabled' => $this->extensions->isEnabled($slug),
        ]);
    }

    public function saveSettings(Request $request, string $slug): RedirectResponse
    {
        $manifest = $this->addonManifest($slug);
        $fields = $this->extensions->addon($slug)->settingsFields();
        $current = $this->extensions->settings($slug);
        $enabled = $request->boolean('enabled');

        $rules = ['enabled' => ['boolean']];

        foreach ($fields as $key => $field) {
            $required = ($field['required'] ?? false) && $enabled && ! ($field['type'] === 'password' && filled($current[$key] ?? null));
            $rules["settings.{$key}"] = array_filter([
                $required ? 'required' : 'nullable',
                'string',
                $field['type'] === 'textarea' ? 'max:5000' : 'max:1000',
                isset($field['options']) ? 'in:'.implode(',', array_keys($field['options'])) : null,
            ]);
        }

        $input = $request->validate($rules, [], collect($fields)->mapWithKeys(fn (array $field, string $key): array => ["settings.{$key}" => $field['label']])->all());
        // Keep values the add-on saved for itself, such as a connection token.
        $settings = $current;

        foreach ($fields as $key => $field) {
            $value = $input['settings'][$key] ?? null;
            $settings[$key] = ($field['type'] === 'password' && blank($value)) ? ($current[$key] ?? null) : $value;
        }

        $this->extensions->saveSettings($slug, $settings, $enabled);
        Activity::log('extension.updated', "Extension {$manifest->name} ".($enabled ? 'switched on' : 'switched off'));

        return redirect()->route('admin.extensions.index', ['tab' => 'addons'])->with('status', __(':name saved.', ['name' => $manifest->name]));
    }

    /**
     * @return Collection<int, Listing>
     */
    private function catalog(bool $fresh = false): Collection
    {
        $installs = MarketplaceInstall::all()->keyBy('slug');

        return collect($this->client->catalog($fresh))
            ->map(fn (array $item): Listing => Listing::fromCatalog($item, $installs->get($item['slug'])))
            ->values();
    }

    /**
     * Everything installed here: marketplace installs, built-in extensions and themes on disk.
     *
     * @param  Collection<int, Listing>  $catalog
     * @return Collection<int, Listing>
     */
    private function installed(Collection $catalog): Collection
    {
        $installs = MarketplaceInstall::all()->keyBy('slug');
        $listed = $catalog->keyBy('slug');
        $local = collect();

        foreach ($this->themes->all() as $slug => $theme) {
            $local->push($listed->get($slug) ?? Listing::local($slug, PackageType::Theme, $theme['name'], $theme['version'], $theme['description'], $theme['author'], ! $installs->has($slug), $installs->get($slug)));
        }

        foreach ($this->themes->orderForms() as $slug => $form) {
            $local->push($listed->get($slug) ?? Listing::local($slug, PackageType::OrderForm, $form['name'], $form['version'], $form['description'], $form['author'], ! $installs->has($slug), $installs->get($slug)));
        }

        foreach ($this->extensions->manifests() as $manifest) {
            $local->push($listed->get($manifest->slug) ?? Listing::local($manifest->slug, PackageType::from($manifest->type), $manifest->name, $manifest->version, $manifest->description, $manifest->author, ! $installs->has($manifest->slug), $installs->get($manifest->slug), $manifest->permissions));
        }

        return $local->filter(fn (Listing $listing): bool => $listing->isInstalled())->sortBy(fn (Listing $listing): string => ($listing->builtIn ? '1' : '0').$listing->name)->values();
    }

    private function find(string $slug): Listing
    {
        $catalog = $this->catalog();

        return $catalog->firstWhere('slug', $slug)
            ?? $this->installed($catalog)->firstWhere('slug', $slug)
            ?? abort(404);
    }

    private function isActive(Listing $listing): bool
    {
        return match ($listing->type) {
            PackageType::Theme => $this->themes->saved() === $listing->slug,
            PackageType::OrderForm => $this->themes->savedOrderForm() === $listing->slug,
            default => $this->extensions->isEnabled($listing->slug),
        };
    }

    private function settingsUrl(Listing $listing): ?string
    {
        if (! $listing->isInstalled() || $this->extensions->find($listing->slug) === null) {
            return null;
        }

        return match ($listing->type) {
            PackageType::Gateway => route('admin.settings.gateways.edit', $listing->slug),
            PackageType::Registrar => route('admin.settings.registrars.edit', $listing->slug),
            PackageType::Server => route('admin.servers.create', ['module' => $listing->slug]),
            PackageType::Addon => route('admin.marketplace.settings', $listing->slug),
            default => null,
        };
    }

    private function addonManifest(string $slug): ExtensionManifest
    {
        $manifest = $this->extensions->find($slug);

        abort_if($manifest === null || $manifest->type !== ExtensionManifest::TYPE_ADDON, 404);

        return $manifest;
    }
}
