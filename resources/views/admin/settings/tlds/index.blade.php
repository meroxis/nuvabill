<x-layouts.admin :title="__('Domains')">
    <x-settings-page>

        <section class="card card-flush">
            <div class="card-header">
                <h2>{{ __('Domain prices') }}</h2>
                <a class="btn btn-sm" href="{{ route('admin.extensions.index', ['tab' => 'registrars']) }}">{{ __('Registrars') }}</a>
            </div>
            @if ($prices->isEmpty())
                <div class="empty"><strong>{{ __('No extensions yet') }}</strong>{{ __('Add .com or another extension below to start selling domains.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Domain ending') }}</th><th class="end">{{ __('Register') }}</th><th class="end">{{ __('Renew') }}</th><th class="end">{{ __('Transfer') }}</th><th>{{ __('Registrar') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($prices as $row)
                        <tr>
                            <td><a class="row-link" href="{{ route('admin.settings.tlds.edit', $row) }}">.{{ $row->tld }}</a> <span class="faint">{{ $row->currency }}</span>@if ($row->is_featured) <x-pill tone="accent">{{ __('Featured') }}</x-pill>@endif</td>
                            <td class="end num">{{ money($row->register_price, $row->currency) }}</td>
                            <td class="end num">{{ money($row->renew_price, $row->currency) }}</td>
                            <td class="end num">{{ money($row->transfer_price, $row->currency) }}</td>
                            <td>{{ $row->registrar ? ($registrars[$row->registrar] ?? $row->registrar) : __('By hand') }}</td>
                            <td>@if ($row->is_enabled)<x-pill tone="good">{{ __('On sale') }}</x-pill>@else<x-pill>{{ __('Off') }}</x-pill>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </section>

        <div class="grid-2">
            <form method="POST" action="{{ route('admin.settings.tlds.store') }}" class="card" style="display:grid;gap:1.1rem">
                @csrf
                <div class="card-header" style="margin:0"><h2>{{ __('Add an extension') }}</h2></div>
                @include('admin.settings.tlds.form', ['price' => $price])
                <div class="form-actions"><button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ __('Add') }}</button></div>
            </form>

            <form method="POST" action="{{ route('admin.settings.tlds.settings') }}" class="card" style="display:grid;gap:1.1rem;align-content:start">
                @csrf
                @method('PUT')
                <div class="card-header" style="margin:0"><h2>{{ __('Domain settings') }}</h2></div>
                <p class="muted" style="margin:0;font-size:.88rem">{{ __('Default nameservers are used for new domains that are not ordered with hosting.') }}</p>
                @php $nameservers = array_pad((array) $settings['domains.nameservers'], 4, ''); @endphp
                <div class="form-grid">
                    @foreach ($nameservers as $index => $nameserver)
                        <x-input :name="'nameservers['.$index.']'" :label="__('Nameserver :number', ['number' => $index + 1])" :value="$nameserver" placeholder="ns{{ $index + 1 }}.yourhost.com" autocomplete="off" />
                    @endforeach
                    <x-input name="renewal_days_before" type="number" min="0" max="90" :label="__('Renewal invoice, days before expiry')" :value="$settings['domains.renewal_days_before']" required />
                    <x-input name="expiry_notice_days" :label="__('Expiry warnings, days before')" :value="implode(', ', (array) $settings['domains.expiry_notice_days'])" :help="__('For domains with auto-renew off. For example: 30, 7')" />
                    <x-checkbox name="auto_register" :label="__('Register domains automatically when the invoice is paid')" :checked="(bool) $settings['domains.auto_register']" class="span-2" />
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save domain settings') }}</button></div>
            </form>
        </div>
    </x-settings-page>
</x-layouts.admin>
