<x-layouts.admin :title="__('Currencies')">
    <x-settings-page>

        <div style="display:grid;gap:14px;max-width:860px">
            <p class="muted" style="margin:0">{{ __('Your prices and invoices are in :base. Some payment gateways only take one currency, for example Wayl, FIB and FastPay take Iraqi dinar (IQD). Add a rate here and clients can still pay with them: the gateway charges the converted amount, and the invoice is marked paid in :base.', ['base' => $base]) }}</p>

            @foreach ($needs as $gateway)
                <div class="flash" data-tone="{{ $gateway['ready'] ? 'info' : 'warn' }}">
                    <span>
                        @if ($gateway['ready'])
                            {{ __(':gateway is ready: it charges the converted amount.', ['gateway' => $gateway['name']]) }}
                        @elseif ($gateway['converts'])
                            {{ __(':gateway does not take :base. Add a rate for a currency it takes, or clients will not see it.', ['gateway' => $gateway['name'], 'base' => $base]) }}
                        @else
                            {{ __(':gateway does not take :base, so clients will not see it.', ['gateway' => $gateway['name'], 'base' => $base]) }}
                        @endif
                    </span>
                </div>
            @endforeach

            @php
                $rows = collect($rates)->map(fn ($rate, $code) => ['code' => $code, 'rate' => rtrim(rtrim(number_format($rate, 6, '.', ''), '0'), '.')])->values()->all();
                $rows = old('rates', $rows ?: [['code' => '', 'rate' => '']]);
            @endphp

            <form method="POST" action="{{ route('admin.settings.currencies.update') }}" class="card" style="display:grid;gap:1rem" x-data="{ rows: @js(array_values($rows)) }">
                @csrf
                @method('PUT')
                <h2 style="font-size:1.05rem">{{ __('Exchange rates') }}</h2>

                <template x-for="(row, index) in rows" :key="index">
                    <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
                        <div class="field" style="flex:0 0 auto">
                            <label :for="'rate-' + index">{{ __('1 :base equals', ['base' => $base]) }}</label>
                            <input class="input" type="text" inputmode="decimal" :id="'rate-' + index" :name="'rates[' + index + '][rate]'" x-model="row.rate" placeholder="1310" style="width:160px">
                        </div>
                        <div class="field" style="flex:0 0 auto">
                            <label :for="'code-' + index">{{ __('Currency') }}</label>
                            <select class="select" :id="'code-' + index" :name="'rates[' + index + '][code]'" x-model="row.code">
                                <option value="">{{ __('Choose') }}</option>
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}">{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <button type="button" class="btn btn-ghost" @click="rows.splice(index, 1)" :aria-label="'{{ __('Remove rate') }} ' + (index + 1)"><x-icon name="x" /></button>
                    </div>
                </template>

                @error('rates.*.rate')<p class="error">{{ $message }}</p>@enderror

                <div style="display:flex;gap:10px;flex-wrap:wrap">
                    <button type="button" class="btn" @click="rows.push({ code: '', rate: '' })"><x-icon name="plus" />{{ __('Add a currency') }}</button>
                    <button class="btn btn-primary" type="submit">{{ __('Save rates') }}</button>
                </div>
                <p class="help" style="margin:0">{{ __('Rates do not change by themselves. Check them often. Iraqi gateways only take whole dinars, so amounts are rounded up.') }}</p>
            </form>
        </div>
    </x-settings-page>
</x-layouts.admin>
