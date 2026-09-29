@php
    $editing = $product->exists;
    $selectedModule = old('server_module', $product->server_module) ?? '';
@endphp
<x-layouts.admin :title="$editing ? __('Edit :name', ['name' => $product->name]) : __('New product')">
    <div class="page-head"><div><h1>{{ $editing ? __('Edit product') : __('New product') }}</h1>@if ($editing)<p>{{ $product->name }}</p>@endif</div></div>

    <form method="POST" action="{{ $editing ? route('admin.products.update', $product) : route('admin.products.store') }}" style="display:grid;gap:14px" x-data="{ module: @js($selectedModule) }">
        @csrf
        @if ($editing) @method('PUT') @endif

        <section class="card" style="display:grid;gap:1.1rem">
            <div class="card-header" style="margin:0"><h2>{{ __('Basics') }}</h2></div>
            <div class="form-grid">
                <x-input name="name" :label="__('Name')" :value="$product->name" required :help="__('For example “Starter Hosting”.')" />
                <x-select name="product_group_id" :label="__('Group')" :options="$groups" :value="$product->product_group_id" required />
                <x-select name="type" :label="__('Type')" :options="collect(\App\Enums\ProductType::cases())->mapWithKeys(fn ($t) => [$t->value => $t->label()])->all()" :value="$product->type" required />
                <x-input name="slug" :label="__('Web address')" :value="$product->slug" :help="__('Leave empty to create it from the name.')" />
                <x-textarea name="description" :label="__('Description')" :value="$product->description" rows="4" class="span-2" :help="__('One feature per line shows as a list in the store.')" />
                @if ($aiWriter)
                    <div class="span-2" style="display:grid;gap:6px" x-data="productAi(@js([
                        'url' => route('admin.products.ai.write'),
                        'product' => $product->id,
                        'failed' => __('AI help could not answer. Please try again.'),
                        'done' => __('Done: the description and the search texts below are filled in. Check them, then save the product.'),
                    ]))">
                        <div class="ai-tools">
                            <button type="button" class="btn btn-sm" @click="write" :disabled="busy"><x-icon name="sparkles" /><span x-text="busy ? @js(__('Writing…')) : @js(__('Write with AI'))"></span></button>
                            <input class="input" style="flex:1 1 260px;min-width:0" type="text" maxlength="500" x-model="instruction"
                                aria-label="{{ __('What the texts should stress') }}" placeholder="{{ __('Optional: what to stress, for example “for small online shops”') }}">
                        </div>
                        <p class="error" style="margin:0" x-show="error" x-text="error" x-cloak role="alert"></p>
                        <p class="help" style="margin:0" x-show="notice" x-text="notice" x-cloak role="status"></p>
                    </div>
                @endif
                <x-checkbox name="is_visible" :label="__('Show in the store')" :checked="$product->is_visible" />
                <x-checkbox name="requires_domain" :label="__('Ask the client for a domain name')" :checked="$product->requires_domain" />
                <x-checkbox name="taxable" :label="__('Charge tax on this product')" :help="__('Only when taxes are on in Settings → Taxes.')" :checked="$product->taxable ?? true" />
            </div>
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <div class="card-header" style="margin:0"><h2>{{ __('Pricing (:currency)', ['currency' => $currency]) }}</h2></div>
            @error('prices')<div class="flash" data-tone="crit">{{ $message }}</div>@enderror
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Billing cycle') }}</th><th>{{ __('Price') }}</th><th>{{ __('Setup fee') }}</th></tr></thead>
                <tbody>
                @foreach (\App\Enums\BillingCycle::cases() as $cycle)
                    @php
                        $existing = $product->exists ? $product->priceFor($currency, $cycle) : null;
                        $enabled = old("prices.{$cycle->value}.enabled", $existing !== null || (! $product->exists && $cycle === \App\Enums\BillingCycle::Monthly));
                    @endphp
                    <tr x-data="{ on: @js((bool) $enabled) }">
                        <td>
                            <input type="hidden" name="prices[{{ $cycle->value }}][enabled]" value="0">
                            <label class="check"><input type="checkbox" name="prices[{{ $cycle->value }}][enabled]" value="1" x-model="on"> {{ $cycle->label() }}</label>
                        </td>
                        <td>
                            @if ($cycle !== \App\Enums\BillingCycle::Free)
                                <input class="input num" style="max-width:140px" type="number" step="0.01" min="0" name="prices[{{ $cycle->value }}][price]" value="{{ old("prices.{$cycle->value}.price", $existing ? \App\Support\Money::toDecimal($existing->price) : '') }}" :disabled="! on" aria-label="{{ __('Price for :cycle', ['cycle' => $cycle->label()]) }}" @error("prices.{$cycle->value}.price") aria-invalid="true" @enderror>
                            @else
                                <span class="muted">{{ __('No charge') }}</span>
                            @endif
                        </td>
                        <td><input class="input num" style="max-width:140px" type="number" step="0.01" min="0" name="prices[{{ $cycle->value }}][setup_fee]" value="{{ old("prices.{$cycle->value}.setup_fee", $existing ? \App\Support\Money::toDecimal($existing->setup_fee) : '0.00') }}" :disabled="! on" aria-label="{{ __('Setup fee for :cycle', ['cycle' => $cycle->label()]) }}"></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>

        <section class="card" style="display:grid;gap:1.1rem">
            <div class="card-header" style="margin:0"><h2>{{ __('Automatic setup') }}</h2></div>
            <div class="form-grid">
                <div class="field">
                    <label for="f-server_module">{{ __('Server module') }}</label>
                    <select id="f-server_module" name="server_module" class="select" x-model="module">
                        <option value="">{{ __('None (staff set it up by hand)') }}</option>
                        @foreach ($modules as $slug => $name)
                            <option value="{{ $slug }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <x-select name="auto_setup" :label="__('Set up the service')" :options="collect(\App\Enums\AutoSetup::cases())->mapWithKeys(fn ($a) => [$a->value => $a->label()])->all()" :value="$product->auto_setup" required />

                @foreach ($moduleFields as $slug => $fields)
                    <template x-if="module === @js($slug)">
                        <div class="form-grid span-2">
                            <div class="field">
                                <label for="f-server_id-{{ $slug }}">{{ __('Server') }}</label>
                                <select id="f-server_id-{{ $slug }}" name="server_id" class="select">
                                    <option value="">{{ __('Any server with free space') }}</option>
                                    @foreach ($servers->where('module', $slug) as $server)
                                        <option value="{{ $server->id }}" @selected((string) old('server_id', $product->server_id) === (string) $server->id)>{{ $server->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <x-extension-fields :fields="$fields" :values="old('module_config', $product->module_config ?? [])" prefix="module_config" />
                        </div>
                    </template>
                @endforeach

                <x-input name="stock" type="number" min="0" :label="__('Stock')" :value="$product->stock" :help="__('Leave empty for unlimited.')" />
                <x-input name="sort_order" type="number" min="0" :label="__('Order in group')" :value="$product->sort_order ?? 0" />
            </div>
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <div class="card-header" style="margin:0"><h2>{{ __('Upgrades and downgrades') }}</h2></div>
            <p class="muted" style="margin:0">{{ __('Clients with this product can switch to the plans you tick. They pay only the difference for the days left. Only plans on the same kind of server are shown.') }}</p>
            @php($chosen = array_map('intval', (array) old('upgrade_product_ids', $product->upgrade_product_ids ?? [])))
            <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:8px 14px">
                @foreach ($otherProducts as $other)
                    <label class="check" for="f-upgrade-{{ $other->id }}" x-show="(module || '') === @js((string) $other->server_module)">
                        <input id="f-upgrade-{{ $other->id }}" type="checkbox" name="upgrade_product_ids[]" value="{{ $other->id }}" @checked(in_array($other->id, $chosen, true))>
                        <span>{{ $other->name }} <span class="muted">· {{ $other->group?->name }}</span></span>
                    </label>
                @endforeach
            </div>
            <p class="muted" x-show="! @js($otherProducts->map(fn ($other): string => (string) $other->server_module)->unique()->values()).includes(module || '')" style="margin:0;font-size:.9rem">{{ __('No other plans use the same server yet.') }}</p>
        </section>

        <section class="card" style="display:grid;gap:1.1rem">
            <div class="card-header" style="margin:0"><h2>{{ __('Search appearance') }}</h2></div>
            <x-search-appearance :title="$product->seo_title" :description="$product->seo_description" :default-title="$seo['title']" :default-description="$seo['description']"
                :suggest-title="$seo['suggestTitle']" :suggest-description="$seo['suggestDescription']" :url="$seo['url']" :hidden="(bool) $product->seo_hidden" />
        </section>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $editing ? __('Save product') : __('Create product') }}</button>
            <a class="btn" href="{{ route('admin.products.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($editing)
        <form method="POST" action="{{ route('admin.products.destroy', $product) }}" data-confirm="{{ __('Delete this product?') }}">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit">{{ __('Delete product') }}</button>
        </form>
    @endif
</x-layouts.admin>
