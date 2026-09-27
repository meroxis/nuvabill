<x-layouts.admin :title="$coupon->exists ? __('Coupon :code', ['code' => $coupon->code]) : __('New coupon')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.coupons.index') }}">{{ __('Coupons') }}</a></p>
            <h1>{{ $coupon->exists ? $coupon->code : __('New coupon') }}</h1>
            @if ($coupon->exists)<p>{{ trans_choice('Used once|Used :count times', $coupon->uses, ['count' => $coupon->uses]) }}</p>@endif
        </div>
        @if ($coupon->exists)
            <form method="POST" action="{{ route('admin.coupons.destroy', $coupon) }}" onsubmit="return confirm(@js(__('Delete this coupon?')))">
                @csrf
                @method('DELETE')
                <button class="btn btn-ghost" type="submit"><x-icon name="trash" />{{ __('Delete') }}</button>
            </form>
        @endif
    </div>

    @php
        $type = old('type', $coupon->type);
        $value = old('value', $coupon->type === \App\Models\Coupon::TYPE_FIXED ? \App\Support\Money::toDecimal((int) $coupon->value) : $coupon->value);
        $selectedProducts = array_map('intval', (array) old('product_ids', $coupon->product_ids ?? []));
        $selectedCycles = (array) old('billing_cycles', $coupon->billing_cycles ?? []);
    @endphp

    <form method="POST" action="{{ $coupon->exists ? route('admin.coupons.update', $coupon) : route('admin.coupons.store') }}" style="display:grid;gap:14px;max-width:860px" x-data="{ type: @js($type), recurring: @js(old('recurring', $coupon->recurring)) }">
        @csrf
        @if ($coupon->exists) @method('PUT') @endif

        <section class="card" style="display:grid;gap:1rem">
            <div class="form-grid">
                <x-input name="code" :label="__('Code')" :value="$coupon->code" required maxlength="40" class="mono" autocomplete="off" spellcheck="false" :help="__('Clients type this code, or open a link that ends with ?coupon=CODE.')" />
                <div class="field">
                    <span class="label" id="type-label">{{ __('Discount') }}</span>
                    <div style="display:flex;gap:8px;align-items:center">
                        <select class="select" name="type" x-model="type" aria-labelledby="type-label" style="max-width:170px">
                            <option value="percent">{{ __('Percent') }}</option>
                            <option value="fixed">{{ __('Fixed amount (:currency)', ['currency' => $currency]) }}</option>
                        </select>
                        <input class="input num" type="number" step="0.01" min="0" name="value" value="{{ $value }}" required aria-label="{{ __('Discount value') }}" style="max-width:130px" @error('value') aria-invalid="true" @enderror>
                        <span class="muted" x-text="type === 'percent' ? '%' : @js($currency)"></span>
                    </div>
                    @error('value')<p class="error">{{ $message }}</p>@enderror
                </div>
            </div>
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <h2 style="font-size:1.05rem">{{ __('Which payments get the discount') }}</h2>
            <div style="display:grid;gap:8px">
                <label class="check"><input type="radio" name="recurring" value="first" x-model="recurring"> <span><b>{{ __('First payment only') }}</b> <span class="muted">· {{ __('renewals are full price') }}</span></span></label>
                <label class="check"><input type="radio" name="recurring" value="every" x-model="recurring"> <span><b>{{ __('Every payment') }}</b> <span class="muted">· {{ __('renewals keep the discount') }}</span></span></label>
                <label class="check"><input type="radio" name="recurring" value="count" x-model="recurring"> <span><b>{{ __('A number of payments') }}</b> <span class="muted">· {{ __('for example the first 3 months') }}</span></span></label>
            </div>
            <div x-show="recurring === 'count'" x-cloak style="max-width:220px">
                <x-input name="recurring_count" type="number" min="2" max="120" :label="__('How many payments')" :value="$coupon->recurring_count ?? 3" />
            </div>
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <h2 style="font-size:1.05rem">{{ __('What it applies to') }}</h2>
            <div class="field">
                <span class="label">{{ __('Products') }} <span class="muted" style="font-weight:400">{{ __('leave all unticked for every product') }}</span></span>
                <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:6px">
                    @foreach ($products as $id => $name)
                        <label class="check"><input type="checkbox" name="product_ids[]" value="{{ $id }}" @checked(in_array($id, $selectedProducts, true))> {{ $name }}</label>
                    @endforeach
                </div>
            </div>
            <div class="field">
                <span class="label">{{ __('Billing cycles') }} <span class="muted" style="font-weight:400">{{ __('leave all unticked for every cycle') }}</span></span>
                <div style="display:flex;gap:14px;flex-wrap:wrap">
                    @foreach ($cycles as $value => $label)
                        <label class="check"><input type="checkbox" name="billing_cycles[]" value="{{ $value }}" @checked(in_array($value, $selectedCycles, true))> {{ $label }}</label>
                    @endforeach
                </div>
            </div>
            <x-checkbox name="applies_to_domains" :label="__('Also discount domain registrations and transfers')" :checked="(bool) $coupon->applies_to_domains" />
        </section>

        <section class="card" style="display:grid;gap:1rem">
            <h2 style="font-size:1.05rem">{{ __('Limits') }}</h2>
            <div class="form-grid">
                <x-input name="starts_at" type="date" :label="__('Starts')" :value="$coupon->starts_at?->toDateString()" />
                <x-input name="ends_at" type="date" :label="__('Ends')" :value="$coupon->ends_at?->toDateString()" />
                <x-input name="max_uses" type="number" min="1" :label="__('Total uses')" :value="$coupon->max_uses" :help="__('Empty means no limit.')" />
                <x-input name="max_uses_per_client" type="number" min="1" :label="__('Uses per client')" :value="$coupon->max_uses_per_client" :help="__('Empty means no limit.')" />
            </div>
            <x-checkbox name="new_clients_only" :label="__('New clients only (no earlier orders)')" :checked="(bool) $coupon->new_clients_only" />
            <x-checkbox name="is_active" :label="__('Coupon is on')" :checked="(bool) $coupon->is_active" />
            <x-textarea name="notes" :label="__('Notes for staff')" :value="$coupon->notes" rows="2" />
        </section>

        <div class="form-actions">
            <a class="btn" href="{{ route('admin.coupons.index') }}">{{ __('Cancel') }}</a>
            <button class="btn btn-primary" type="submit">{{ __('Save coupon') }}</button>
        </div>
    </form>
</x-layouts.admin>
