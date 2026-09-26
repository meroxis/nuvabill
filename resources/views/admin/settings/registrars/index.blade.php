<x-layouts.admin :title="__('Registrars')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <section class="card card-flush">
        <div class="card-header">
            <h2>{{ __('Domain registrars') }}</h2>
            <a class="btn btn-sm" href="{{ route('admin.settings.tlds.index') }}">{{ __('Domain prices') }}</a>
        </div>
        @if ($registrars->isEmpty())
            <div class="empty"><strong>{{ __('No registrar modules installed') }}</strong></div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Registrar') }}</th><th>{{ __('Version') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($registrars as $slug => $registrar)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.settings.registrars.edit', $slug) }}">{{ $registrar['manifest']->name }}</a><div class="faint" style="font-size:.8rem">{{ $registrar['manifest']->description }}</div></td>
                        <td class="mono faint">{{ $registrar['manifest']->version }}</td>
                        <td>
                            @if ($registrar['enabled'] && $registrar['configured'])
                                <x-pill tone="good">{{ __('On') }}</x-pill>
                            @elseif ($registrar['enabled'])
                                <x-pill tone="warn">{{ __('Needs settings') }}</x-pill>
                            @else
                                <x-pill>{{ __('Off') }}</x-pill>
                            @endif
                        </td>
                        <td class="end"><a class="btn btn-sm" href="{{ route('admin.settings.registrars.edit', $slug) }}">{{ __('Set up') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</x-layouts.admin>
