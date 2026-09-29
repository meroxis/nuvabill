<?php

namespace App\Http\Controllers\Admin;

use App\Ai\Claude;
use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Extensions\ExtensionManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ProductRequest;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\Server;
use App\Seo\SeoText;
use App\Seo\SiteAddress;
use App\Support\Activity;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProductController extends Controller
{
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
        });

        Activity::log('product.updated', "Product {$product->name} updated", $product);

        return redirect()->route('admin.products.index')->with('status', __('Product saved. Existing services keep their current price.'));
    }

    public function destroy(Product $product): RedirectResponse
    {
        if ($product->services()->exists()) {
            return back()->with('error', __('Clients own this product, so it cannot be deleted. Hide it from the store instead.'));
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
            'servers' => Server::query()->orderBy('name')->get(['id', 'name', 'module']),
            'currency' => setting('billing.currency'),
            'aiWriter' => app(Claude::class)->isOn('descriptions') && auth('admin')->user()->hasPermission('ai.use'),
            'seo' => [
                'title' => $product->exists ? SeoText::withPattern($product->name) : '',
                'description' => $product->exists ? SeoText::suggestProductDescription($product) : '',
                'suggestTitle' => $product->exists ? SeoText::suggestProductTitle($product) : null,
                'suggestDescription' => $product->exists ? SeoText::suggestProductDescription($product) : null,
                'url' => SiteAddress::url($product->exists ? $product->storePath() : 'store'),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(ProductRequest $request): array
    {
        $data = $request->safe()->except('prices');
        $data['module_config'] = $data['server_module'] ? array_filter($data['module_config'] ?? [], fn ($value) => $value !== null) : null;
        $data['server_id'] = $data['server_module'] ? ($data['server_id'] ?? null) : null;
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
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
