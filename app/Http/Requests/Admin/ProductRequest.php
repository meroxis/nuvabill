<?php

namespace App\Http\Requests\Admin;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Extensions\ExtensionManager;
use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => Str::slug((string) ($this->input('slug') ?: $this->input('name'))),
            'server_module' => $this->input('server_module') ?: null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Product|null $product */
        $product = $this->route('product');
        $modules = app(ExtensionManager::class)->serverModuleNames()->keys()->all();

        return [
            'product_group_id' => ['required', 'exists:product_groups,id'],
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'max:120', Rule::unique('products', 'slug')->ignore($product?->id)],
            'type' => ['required', Rule::enum(ProductType::class)],
            'description' => ['nullable', 'string', 'max:5000'],
            'is_visible' => ['boolean'],
            'requires_domain' => ['boolean'],
            'server_module' => ['nullable', Rule::in($modules)],
            'server_id' => ['nullable', 'exists:servers,id'],
            'module_config' => ['nullable', 'array'],
            'module_config.*' => ['nullable', 'string', 'max:190'],
            'auto_setup' => ['required', Rule::enum(AutoSetup::class)],
            'stock' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'prices' => ['required', 'array'],
            'prices.*.enabled' => ['boolean'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'prices.*.setup_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $enabled = collect($this->input('prices', []))
                    ->filter(fn (mixed $price, mixed $cycle): bool => is_array($price) && ! empty($price['enabled']) && BillingCycle::tryFrom((string) $cycle) !== null);

                if ($enabled->isEmpty()) {
                    $validator->errors()->add('prices', __('Turn on at least one billing cycle and give it a price.'));
                }

                foreach ($enabled as $cycle => $price) {
                    if ($cycle !== BillingCycle::Free->value && ! is_numeric($price['price'] ?? null)) {
                        $validator->errors()->add("prices.{$cycle}.price", __('Enter a price for :cycle.', ['cycle' => BillingCycle::from($cycle)->label()]));
                    }
                }
            },
        ];
    }
}
