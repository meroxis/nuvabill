{{-- Fields for one domain extension's prices. Used on the list page (add) and the edit page. --}}
<div class="form-grid">
    <x-input name="tld" :label="__('Domain ending')" :value="$price->tld" placeholder="com" :help="__('Without the dot, for example com, net or co.uk.')" required />
    <x-input name="currency" :label="__('Currency')" :value="$price->currency" maxlength="3" required />
    <x-input name="register_price" type="number" step="0.01" min="0" :label="__('Register price per year')" :value="$price->exists ? \App\Support\Money::toDecimal($price->register_price) : null" required />
    <x-input name="renew_price" type="number" step="0.01" min="0" :label="__('Renew price per year')" :value="$price->exists ? \App\Support\Money::toDecimal($price->renew_price) : null" required />
    <x-input name="transfer_price" type="number" step="0.01" min="0" :label="__('Transfer price')" :value="$price->exists ? \App\Support\Money::toDecimal($price->transfer_price) : null" :help="__('A transfer usually includes one year.')" required />
    <x-select name="registrar" :label="__('Registrar')" :options="$registrars" :value="$price->registrar" :placeholder="__('None: I register these by hand')" />
    <x-input name="min_years" type="number" min="1" max="10" :label="__('Shortest period (years)')" :value="$price->min_years" required />
    <x-input name="max_years" type="number" min="1" max="10" :label="__('Longest period (years)')" :value="$price->max_years" required />
    <x-input name="sort_order" type="number" min="0" :label="__('Sort order')" :value="$price->sort_order" />
    <div class="span-2" style="display:grid;gap:.6rem">
        <x-checkbox name="epp_required" :label="__('Transfers need an authorization (EPP) code')" :checked="$price->epp_required" />
        <x-checkbox name="is_featured" :label="__('Show first in domain search results')" :checked="$price->is_featured" />
        <x-checkbox name="is_enabled" :label="__('Sell this extension')" :checked="$price->is_enabled" />
    </div>
</div>
