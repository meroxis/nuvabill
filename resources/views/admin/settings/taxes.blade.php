<x-layouts.admin :title="__('Taxes')">
    <x-settings-page>

        <div style="display:grid;gap:14px;max-width:960px">
            <form method="POST" action="{{ route('admin.settings.taxes.settings') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                @method('PUT')
                <div class="card-header" style="margin:0"><h2>{{ __('Taxes') }}</h2><span class="faint" style="font-size:.85rem">{{ __('Add VAT, GST or sales tax to invoices, based on where each client lives.') }}</span></div>
                <div class="form-grid">
                    <x-checkbox name="enabled" :label="__('Charge tax')" :help="__('New invoices get the tax rule for the client\'s country. Invoices already sent do not change.')" :checked="setting('tax.enabled')" class="span-2" />
                    <x-checkbox name="inclusive" :label="__('My prices already include tax')" :help="__('On: the price clients see is what they pay, and the tax is shown as the part inside it. Off: tax is added on top.')" :checked="setting('tax.inclusive')" class="span-2" />
                    <x-checkbox name="domains" :label="__('Charge tax on domains')" :checked="setting('tax.domains')" class="span-2" />
                    <x-input name="id_label" :label="__('Name of the tax number')" :value="setting('tax.id_label')" required :help="__('For example VAT number, GST number or Tax ID. Clients can add theirs to their account.')" />
                    <x-input name="company_tax_id" :label="__('Your tax number')" :value="setting('company.tax_id')" :help="__('Printed on your invoices. Leave empty if you do not have one.')" />
                </div>
                <div><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
            </form>

            <section class="card" style="display:grid;gap:1rem">
                <div class="card-header" style="margin:0"><h2>{{ __('Tax rules') }}</h2><span class="faint" style="font-size:.85rem">{{ __('The most exact match is used: country and state, then country, then everyone.') }}</span></div>

                @forelse ($rules as $rule)
                    <form method="POST" action="{{ route('admin.settings.taxes.update', $rule) }}" style="display:grid;grid-template-columns:minmax(0,1.1fr) 100px minmax(0,1.3fr) minmax(0,1fr) auto;gap:8px;align-items:end;padding-bottom:1rem;border-bottom:1px solid var(--nb-line)">
                        @csrf
                        @method('PUT')
                        <x-input name="name" :label="__('Name')" :value="$rule->name" :id="'t-name-'.$rule->id" required />
                        <x-input name="rate" :label="__('Rate %')" :value="rtrim(rtrim(number_format($rule->rate / 100, 2, '.', ''), '0'), '.')" :id="'t-rate-'.$rule->id" inputmode="decimal" required />
                        <x-select name="country" :label="__('Country')" :options="$countries" :value="$rule->country" :placeholder="__('Everyone')" :id="'t-country-'.$rule->id" />
                        <x-input name="state" :label="__('State (optional)')" :value="$rule->state" :id="'t-state-'.$rule->id" />
                        <div style="display:flex;gap:6px">
                            <button class="btn" type="submit">{{ __('Save') }}</button>
                            <button class="btn btn-ghost" type="submit" form="t-delete-{{ $rule->id }}" aria-label="{{ __('Remove :name', ['name' => $rule->name.' '.$rule->placeLabel()]) }}"><x-icon name="trash" /></button>
                        </div>
                    </form>
                    <form id="t-delete-{{ $rule->id }}" method="POST" action="{{ route('admin.settings.taxes.destroy', $rule) }}" data-confirm="{{ __('Remove this tax rule?') }}" hidden>
                        @csrf
                        @method('DELETE')
                    </form>
                @empty
                    <p class="muted" style="margin:0">{{ __('No tax rules yet. Add one below, for example VAT 20% for United Kingdom.') }}</p>
                @endforelse

                <form method="POST" action="{{ route('admin.settings.taxes.store') }}" style="display:grid;grid-template-columns:minmax(0,1.1fr) 100px minmax(0,1.3fr) minmax(0,1fr) auto;gap:8px;align-items:end">
                    @csrf
                    <x-input name="name" :label="__('New rule')" id="t-new-name" required :placeholder="__('For example VAT')" />
                    <x-input name="rate" :label="__('Rate %')" id="t-new-rate" inputmode="decimal" required placeholder="20" />
                    <x-select name="country" :label="__('Country')" :options="$countries" :placeholder="__('Everyone')" id="t-new-country" />
                    <x-input name="state" :label="__('State (optional)')" id="t-new-state" />
                    <button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ __('Add') }}</button>
                </form>
            </section>
        </div>
    </x-settings-page>
</x-layouts.admin>
