<?php

namespace App\Http\Requests\Admin;

use App\Enums\AutoSetup;
use App\Enums\BillingCycle;
use App\Enums\ProductType;
use App\Extensions\ExtensionManager;
use App\Extensions\Servers\ModuleConfig;
use App\Models\Machine;
use App\Models\Product;
use App\Models\Service;
use App\Seo\SeoTranslations;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Throwable;

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
            'taxable' => ['boolean'],
            'server_module' => ['nullable', Rule::in($modules)],
            'server_id' => ['nullable', 'exists:servers,id'],
            'module_config' => ['nullable', 'array', 'max:200'],
            // Each value is a string; its length is checked per field in after() (16 KB for a long textarea).
            'module_config.*' => ['nullable', 'string', 'max:'.ModuleConfig::MAX_LONG],
            'auto_setup' => ['required', Rule::enum(AutoSetup::class)],
            'stock' => ['nullable', 'integer', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'seo_title' => ['nullable', 'string', 'max:120'],
            'seo_description' => ['nullable', 'string', 'max:320'],
            'seo_hidden' => ['boolean'],
            'upgrade_product_ids' => ['nullable', 'array'],
            'upgrade_product_ids.*' => ['integer', 'exists:products,id'],
            // Client panel switches by section key. Only the sections the module declares are kept.
            'panel_sections' => ['nullable', 'array', 'max:64'],
            'panel_sections.*' => ['boolean'],
            'prices' => ['required', 'array'],
            'prices.*.enabled' => ['boolean'],
            'prices.*.price' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'prices.*.setup_fee' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            ...SeoTranslations::rules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return SeoTranslations::attributes();
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

                if ($this->changesModuleOfHeldMachines()) {
                    $validator->errors()->add('server_module', __('This product has servers in use, so its module cannot change.'));
                }

                $this->checkModuleConfigLengths($validator);
            },
        ];
    }

    /**
     * After an error the form comes back with what was typed, but never with a secret product
     * setting (ModuleConfig::isSecret, such as a private key in a long textarea): it is taken out
     * of the request the error page is made from, so it never reaches the session or the page.
     * When the module cannot list its fields, no product setting comes back at all.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $config = $this->input('module_config');

        if (is_array($config)) {
            $fields = $this->moduleFields();
            $kept = $fields === null ? [] : array_filter(
                $config,
                fn (mixed $value, mixed $key): bool => ! ModuleConfig::isSecret(is_array($fields[$key] ?? null) ? $fields[$key] : []),
                ARRAY_FILTER_USE_BOTH,
            );

            // The app's own request: the error handler flashes its input, not this copy's.
            foreach ([$this, app('request')] as $request) {
                $request->merge(['module_config' => $kept]);
            }
        }

        parent::failedValidation($validator);
    }

    /**
     * The product fields of the chosen server module, [] without a module, or null when the module
     * fails to give them.
     *
     * @return array<mixed>|null
     */
    private function moduleFields(): ?array
    {
        $slug = (string) $this->input('server_module');

        if ($slug === '') {
            return [];
        }

        try {
            $fields = app(ExtensionManager::class)->serverModule($slug)->productFields();
        } catch (Throwable) {
            return null;
        }

        return is_array($fields) ? $fields : null;
    }

    /**
     * Each product setting may be as long as its field allows: 190 characters, or up to 16 KB for
     * a textarea the module gives a "max" (see ModuleConfig). Settings the module does not declare
     * keep the 190 limit, and all of them together stay under 64 KB.
     */
    private function checkModuleConfigLengths(Validator $validator): void
    {
        $config = $this->input('module_config');
        $slug = (string) $this->input('server_module');

        if (! is_array($config) || $slug === '') {
            return;
        }

        try {
            $fields = app(ExtensionManager::class)->serverModule($slug)->productFields();
        } catch (Throwable) {
            $fields = [];
        }

        $total = 0;

        foreach ($config as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $field = is_array($fields[$key] ?? null) ? $fields[$key] : [];
            $max = ModuleConfig::maxLength($field);
            $total += mb_strlen($value);

            if (mb_strlen($value) > $max) {
                $validator->errors()->add('module_config.'.$key, __('The :field may have at most :max characters.', [
                    'field' => is_string($field['label'] ?? null) ? $field['label'] : $key,
                    'max' => $max,
                ]));
            }
        }

        if ($total > ModuleConfig::MAX_TOTAL) {
            $validator->errors()->add('module_config', __('The server settings of this product are too long together. Keep them under :max characters.', ['max' => ModuleConfig::MAX_TOTAL]));
        }
    }

    /**
     * Whether the form moves a product to another server module while some of its services hold a
     * machine from Admin → Hardware. Those services would lose the module that runs their machine.
     */
    private function changesModuleOfHeldMachines(): bool
    {
        $product = $this->route('product');

        if (! $product instanceof Product || (string) $product->server_module === (string) $this->input('server_module')) {
            return false;
        }

        return Machine::query()
            ->whereIn('service_id', Service::query()->select('id')->where('product_id', $product->id))
            ->exists();
    }
}
