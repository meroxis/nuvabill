<x-layouts.admin :title="__('Payment gateways')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <section class="card card-flush">
        <div class="card-header"><h2>{{ __('Payment gateways') }}</h2><span class="faint" style="font-size:.85rem">{{ __('More gateways will come from the marketplace.') }}</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Gateway') }}</th><th>{{ __('Version') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
            <tbody>
            @foreach ($gateways as $slug => $gateway)
                <tr>
                    <td><a class="row-link" href="{{ route('admin.settings.gateways.edit', $slug) }}">{{ $gateway['manifest']->name }}</a><div class="faint" style="font-size:.8rem">{{ $gateway['manifest']->description }}</div></td>
                    <td class="mono faint">{{ $gateway['manifest']->version }}</td>
                    <td>
                        @if ($gateway['enabled'] && $gateway['configured'])
                            <x-pill tone="good">{{ __('On') }}</x-pill>
                        @elseif ($gateway['enabled'])
                            <x-pill tone="warn">{{ __('Needs settings') }}</x-pill>
                        @else
                            <x-pill>{{ __('Off') }}</x-pill>
                        @endif
                    </td>
                    <td class="end"><a class="btn btn-sm" href="{{ route('admin.settings.gateways.edit', $slug) }}">{{ __('Set up') }}</a></td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</x-layouts.admin>
