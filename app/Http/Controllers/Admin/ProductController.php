<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Claude;
use App\Contracts\HasPanelSections;
use App\Contracts\UsesMachineInventory;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\ModuleConfig;
use App\Extensions\Servers\Panel\PanelSection;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\PlanChange;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Provisioning\Provisioner;
use App\Seo\SeoText;
use App\Seo\SeoTranslations;
use App\Seo\SiteAddress;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

class ProductController extends Controller
{
    /**
     * Module settings that no product form shows, so a save must not drop them: the old Virtualizor
     * resource add-on JSON (removed only with "Remove the old add-on settings" on the Resource
     * options page) and the note of its move to resource options. They stay while the module stays.
     */
    private const KEPT_MODULE_CONFIG = ['resource_addons', 'resource_addons_converted'];

    public function index(): View
    {
        return view('admin.products.index', [
            'groups' => ProductGroup::query()->with('products.prices')->withCount('products')->orderBy('sort_order')->orderBy('id')->get(),
            'currency' => setting('billing.currency'),
        ]);
    }

    public function create(Request $request, ExtensionManager $extensions): View
    {
        return $this->form(new Product([
            'product_group_id' => $request->integer('group') ?: null,
            'type' => ProductType::Hosting,
            'auto_setup' => AutoSetup::OnPayment,
            'is_visible' => true,
            'requires_domain' => true,
        ]), $extensions);
    }

    public function store(ProductRequest $request): RedirectResponse
    {
        $product = DB::transaction(function () use ($request): Product {
            $product = Product::create($this->attributes($request));
            $this->syncPrices($product, $request->input('prices', []));
            SeoTranslations::save($product, (array) $request->validated('seo_translations', []));

            return $product;
        });

        Activity::log('product.created', "Product {$product->name} created", $product);

        return redirect()->route('admin.products.index')->with('status', __('Product created.'));
    }

    public function edit(Product $product, ExtensionManager $extensions): View
    {
        return $this->form($product->load('prices'), $extensions);
    }

    public function update(ProductRequest $request, Product $product): RedirectResponse
    {
        DB::transaction(function () use ($request, $product): void {
            $product->update($this->attributes($request));
            $this->syncPrices($product, $request->input('prices', []));
            SeoTranslations::save($product, (array) $request->validated('seo_translations', []));
        });

        Activity::log('product.updated', "Product {$product->name} updated", $product);

        return redirect()->route('admin.products.index')->with('status', __('Product saved. Existing services keep their current price.'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->services()->exists()) {
            return back()->with('error', __('Clients own this product, so it cannot be deleted. Hide it from the store instead.'));
        }

        if (PlanChange::query()->where('to_product_id', $product->id)->where('status', PlanChange::STATUS_PENDING)->exists()) {
            return back()->with('error', __('A client is waiting to move to this product. Stop that plan change first, or hide the product instead.'));
        }

        $product->delete();
        Activity::log('product.deleted', "Product {$product->name} deleted");

        return redirect()->route('admin.products.index')->with('status', __('Product deleted.'));
    }

    private function form(Product $product, ExtensionManager $extensions): View
    {
        $modules = $extensions->serverModuleNames();

        return view('admin.products.form', [
            'product' => $product,
            'groups' => ProductGroup::query()->orderBy('sort_order')->pluck('name', 'id')->all(),
            'modules' => $modules->all(),
            'moduleFields' => $modules->keys()->mapWithKeys(fn (string $slug): array => [$slug => $extensions->serverModule($slug)->productFields()])->all(),
            // Modules whose services run on machines from Admin → Hardware: no stock field and no server.
            'inventoryModules' => $modules->keys()->filter(fn (string $slug): bool => $extensions->serverModule($slug) instanceof UsesMachineInventory)->values()->all(),
            'servers' => Server::query()->orderBy('name')->get(['id', 'name', 'module']),
            'otherProducts' => Product::query()->with('group')->whereKeyNot($product->id ?? 0)->orderBy('product_group_id')->orderBy('sort_order')->get(['id', 'name', 'product_group_id', 'server_module']),
            'currency' => setting('billing.currency'),
            'aiWriter' => app(Claude::class)->isOn('descriptions') && auth('admin')->user()->hasPermission('ai.use'),
            'seo' => [
                'title' => $product->exists ? SeoText::withPattern($product->name) : '',
                'description' => $product->exists ? SeoText::suggestProductDescription($product) : '',
                'suggestTitle' => $product->exists ? SeoText::suggestProductTitle($product) : null,
                'suggestDescription' => $product->exists ? SeoText::suggestProductDescription($product) : null,
                'url' => SiteAddress::url($product->exists ? $product->storePath() : 'store'),
            ],
            'seoTranslations' => $this->seoTranslations($product),
        ]);
    }

    /**
     * The "Other languages" search texts of the form, with the main-language texts they fall back to.
     *
     * @return array{values: array<string, array{title: string, description: string}>, ready: list<string>, mainTitle: string, mainDescription: string}
     */
    private function seoTranslations(Product $product): array
    {
        $mainTitle = $product->exists ? SeoText::productTitle($product) : '';
        $mainDescription = $product->exists ? SeoText::productDescription($product) : '';

        return SeoTranslations::form($product, $mainTitle, $mainDescription) + ['mainTitle' => $mainTitle, 'mainDescription' => $mainDescription];
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProductRequest $request): array
    {
        $data = $request->safe()->except('prices', 'panel_sections', 'seo_translations');
        $data['module_config'] = $data['server_module'] ? array_filter($data['module_config'] ?? [], fn ($value) => $value !== null) : null;
        $saved = $request->route('product');

        if ($saved instanceof Product && $data['server_module'] !== null && $saved->server_module === $data['server_module']) {
            $data['module_config'] += Arr::only($saved->module_config ?? [], self::KEPT_MODULE_CONFIG);
        }

        if ($data['server_module'] !== null) {
            $data['module_config'] = $this->protectSecrets($data['server_module'], $data['module_config'], $saved instanceof Product && $saved->server_module === $data['server_module'] ? ($saved->module_config ?? []) : []);
        }

        $data['server_id'] = $data['server_module'] ? ($data['server_id'] ?? null) : null;
        $data['sort_order'] = $data['sort_order'] ?? 0;
        // Plan changes only work between products on the same kind of server.
        $data['upgrade_product_ids'] = Product::query()
            ->whereIn('id', array_map('intval', $data['upgrade_product_ids'] ?? []))
            ->whereKeyNot($request->route('product')?->id ?? 0)
            ->where(fn ($query) => $data['server_module'] ? $query->where('server_module', $data['server_module']) : $query->whereNull('server_module'))
            ->pluck('id')->all();

        // A saved flag, so stock counts and checkouts never need the extension manager. Such a product's
        // stock is its free machines, and its services run on a machine, not on a server record.
        $data['uses_machines'] = $data['server_module'] !== null
            && app(Provisioner::class)->productSupports(new Product(['server_module' => $data['server_module']]), UsesMachineInventory::class);

        if ($data['uses_machines']) {
            $data['stock'] = null;
            $data['server_id'] = null;
        }

        $data['panel_sections'] = $this->panelSections($request, $data);

        return $data;
    }

    /**
     * Settings the module marks secret (and passwords) are stored encrypted. The form never shows
     * them, so one left empty keeps the saved value.
     *
     * @param  array<string, mixed>  $config  The posted settings.
     * @param  array<string, mixed>  $saved  The product's saved settings for the same module.
     * @return array<string, mixed>
     */
    private function protectSecrets(string $slug, array $config, array $saved): array
    {
        try {
            $fields = app(ExtensionManager::class)->serverModule($slug)->productFields();
        } catch (Throwable) {
            return $config;
        }

        foreach ($fields as $key => $field) {
            if (! is_array($field) || ! ModuleConfig::isSecret($field)) {
                continue;
            }

            $value = $config[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $config[$key] = ModuleConfig::encrypt($value);
            } elseif (isset($saved[$key]) && $saved[$key] !== '') {
                // Saved before 0.7.0 in plain text: encrypted now.
                $config[$key] = ModuleConfig::isEncrypted($saved[$key]) ? $saved[$key] : ModuleConfig::encrypt((string) $saved[$key]);
            }
        }

        return $config;
    }

    /**
     * The client panel switches to save: true or false for each section the chosen module declares
     * for the product, and nothing else. A declared section that was not posted keeps its saved
     * switch, or the module's default. Null for a module without panel sections. When the module
     * cannot list its sections, the saved switches stay as they are.
     *
     * @param  array<string, mixed>  $data  The product attributes about to be saved.
     * @return array<string, bool>|null
     */
    private function panelSections(ProductRequest $request, array $data): ?array
    {
        $saved = $request->route('product');
        $saved = $saved instanceof Product ? $saved : null;
        $slug = (string) ($data['server_module'] ?? '');

        if ($slug === '' || ! app(Provisioner::class)->productSupports(new Product(['server_module' => $slug]), HasPanelSections::class)) {
            return null;
        }

        try {
            /** @var HasPanelSections $module */
            $module = app(ExtensionManager::class)->serverModule($slug);
            $declared = $module->panelSections($saved !== null ? (clone $saved)->fill($data) : new Product($data));
        } catch (Throwable $exception) {
            // Module errors can hold server addresses with keys, so only the class name is reported.
            report(new RuntimeException("Panel sections of {$slug}: ".class_basename($exception)));

            return $saved?->panel_sections;
        }

        $posted = (array) $request->validated('panel_sections', []);
        $sections = [];

        foreach ($declared as $section) {
            if (! $section instanceof PanelSection) {
                continue;
            }

            $sections[$section->key] = array_key_exists($section->key, $posted)
                ? (bool) $posted[$section->key]
                : ($saved?->panelSectionEnabled($section->key, $section->enabledByDefault) ?? $section->enabledByDefault);
        }

        return $sections;
    }

    /**
     * @param  array<string, array{enabled?: bool|string, price?: string|null, setup_fee?: string|null}>  $prices
     */
    private function syncPrices(Product $product, array $prices): void
    {
        $currency = (string) setting('billing.currency');

        foreach (BillingCycle::cases() as $cycle) {
            $input = $prices[$cycle->value] ?? [];

            if (empty($input['enabled'])) {
                $product->prices()->where('currency', $currency)->where('billing_cycle', $cycle)->delete();

                continue;
            }

            $product->prices()->updateOrCreate(
                ['currency' => $currency, 'billing_cycle' => $cycle],
                [
                    'price' => $cycle === BillingCycle::Free ? 0 : Money::toMinor($input['price'] ?? 0),
                    'setup_fee' => Money::toMinor($input['setup_fee'] ?? 0),
                ],
            );
        }
    }
}
