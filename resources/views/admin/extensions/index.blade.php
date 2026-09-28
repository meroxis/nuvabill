@use('App\Extensions\ExtensionManifest')
@use('App\Extensions\ExtensionOverview')
@use('App\Http\Controllers\Admin\ExtensionController')
<x-layouts.admin :title="__('Extensions')">
    @php
        $tabs = ['all' => __('All'), 'gateways' => __('Payment gateways'), 'servers' => __('Server modules'), 'registrars' => __('Registrars'), 'addons' => __('Add-ons')];
        $types = [ExtensionManifest::TYPE_GATEWAY => __('Payment gateway'), ExtensionManifest::TYPE_SERVER => __('Server module'), ExtensionManifest::TYPE_REGISTRAR => __('Registrar'), ExtensionManifest::TYPE_ADDON => __('Add-on')];
        $mayMarketplace = $may[ExtensionManifest::TYPE_ADDON];
    @endphp

    <div class="page-head">
        <div>
            <h1>{{ __('Extensions') }}</h1>
            <p>{{ __('Payment gateways, server modules, registrars and add-ons on this site. Switch them on, fill in their settings and see how much each is used.') }}</p>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="GET" role="search">
                @if ($tab !== 'all')<input type="hidden" name="tab" value="{{ $tab }}">@endif
                <input class="input" type="search" name="q" value="{{ $search }}" placeholder="{{ __('Search extensions') }}" aria-label="{{ __('Search extensions') }}" style="width:220px">
            </form>
            @if ($mayMarketplace)
                <a class="btn btn-primary" href="{{ route('admin.marketplace.index', ['tab' => 'extensions']) }}"><x-icon name="puzzle" />{{ __('Get more extensions') }}</a>
            @endif
        </div>
    </div>

    <div class="kpis">
        <div class="kpi"><small>{{ __('Working') }}</small><b>{{ $totals['on'] }}</b><span>{{ __('Switched on or in use') }}</span></div>
        <div class="kpi"><small>{{ __('Need settings') }}</small><b @if ($totals['needs']) style="color:var(--nb-warn)" @endif>{{ $totals['needs'] }}</b><span>{{ __('Switched on, but not working yet') }}</span></div>
        <div class="kpi"><small>{{ __('Updates') }}</small><b>{{ $totals['updates'] }}</b><span>@if ($totals['updates'] && $mayMarketplace)<a href="{{ route('admin.marketplace.index', ['tab' => 'updates']) }}">{{ __('Update them') }}</a>@else{{ __('New versions in the marketplace') }}@endif</span></div>
        <div class="kpi"><small>{{ __('From the marketplace') }}</small><b>{{ $totals['marketplace'] }}</b><span>{{ __('Built in: :count', ['count' => $counts['all'] - $totals['marketplace']]) }}</span></div>
    </div>

    <nav class="market-tabs" aria-label="{{ __('Extension types') }}" style="margin-top:6px">
        @foreach ($tabs as $key => $label)
            <a href="{{ route('admin.extensions.index', array_filter(['tab' => $key === 'all' ? null : $key, 'q' => $search ?: null])) }}" @if ($tab === $key) aria-current="page" @endif>
                {{ $label }}<span class="count">{{ $counts[$key] }}</span>
            </a>
        @endforeach
    </nav>

    <section class="card card-flush" style="margin-top:14px">
        @if ($items->isEmpty())
            <div class="empty">
                <strong>{{ $search !== '' ? __('Nothing matches your search') : __('Nothing installed here yet') }}</strong>
                @if ($mayMarketplace)<a class="btn btn-sm" href="{{ route('admin.marketplace.index', ['tab' => 'extensions']) }}" style="margin-top:.6rem">{{ __('Find one in the marketplace') }}</a>@endif
            </div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Extension') }}</th><th>{{ __('Status') }}</th><th>{{ __('Usage') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($items as $slug => $item)
                    @php
                        $manifest = $item['manifest'];
                        $isServer = $manifest->type === ExtensionManifest::TYPE_SERVER;
                        $canChange = $may[$manifest->type];
                        $settingsUrl = $canChange && ! $isServer ? ExtensionController::settingsUrl($manifest) : null;
                    @endphp
                    <tr>
                        <td style="white-space:normal;min-width:260px">
                            @if ($settingsUrl)
                                <a class="row-link" href="{{ $settingsUrl }}">{{ $manifest->name }}</a>
                            @else
                                <strong style="font-weight:600">{{ $manifest->name }}</strong>
                            @endif
                            <span class="faint mono" style="font-size:.78rem;margin-inline-start:.3rem">{{ $manifest->version }}</span>
                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:.3rem">
                                @if ($tab === 'all')<x-pill>{{ $types[$manifest->type] }}</x-pill>@endif
                                <x-pill>{{ $item['marketplace'] ? __('Marketplace') : __('Built in') }}</x-pill>
                                @if ($item['update'])
                                    @if ($mayMarketplace)
                                        <a href="{{ route('admin.marketplace.show', $slug) }}" style="text-decoration:none"><x-pill tone="info">{{ __('Update to :version', ['version' => $item['update']]) }}</x-pill></a>
                                    @else
                                        <x-pill tone="info">{{ __('Update to :version', ['version' => $item['update']]) }}</x-pill>
                                    @endif
                                @endif
                                @if ($item['unlicensed'])<x-pill tone="crit">{{ __('No valid license') }}</x-pill>@endif
                            </div>
                            @if ($manifest->description !== '')
                                <div class="faint" style="font-size:.8rem;margin-top:.3rem">{{ $manifest->description }}</div>
                            @endif
                        </td>
                        <td>
                            @switch ($item['state'])
                                @case (ExtensionOverview::STATE_ON)
                                    <x-pill tone="good">{{ $isServer ? __('In use') : __('On') }}</x-pill>
                                    @break
                                @case (ExtensionOverview::STATE_NEEDS_SETTINGS)
                                    <x-pill tone="warn">{{ __('Needs settings') }}</x-pill>
                                    @break
                                @case (ExtensionOverview::STATE_UNUSED)
                                    <x-pill>{{ __('Not used yet') }}</x-pill>
                                    @break
                                @default
                                    <x-pill>{{ __('Off') }}</x-pill>
                            @endswitch
                        </td>
                        <td class="muted" style="font-size:.85rem">{{ $item['usage'] ?? '—' }}</td>
                        <td class="end">
                            <div style="display:inline-flex;gap:6px;flex-wrap:wrap;justify-content:flex-end">
                                @if ($item['page'])
                                    <a class="btn btn-sm" href="{{ $item['page'] }}"><x-icon name="external" />{{ __('Open') }}</a>
                                @endif
                                @if ($isServer && $canChange)
                                    @if ($item['state'] === ExtensionOverview::STATE_ON)
                                        <a class="btn btn-sm" href="{{ route('admin.servers.index') }}">{{ __('Servers') }}</a>
                                    @endif
                                    <a class="btn btn-sm" href="{{ route('admin.servers.create', ['module' => $slug]) }}"><x-icon name="plus" />{{ __('Add a server') }}</a>
                                @elseif ($settingsUrl)
                                    <a class="btn btn-sm {{ $item['state'] === ExtensionOverview::STATE_NEEDS_SETTINGS ? 'btn-primary' : '' }}" href="{{ $settingsUrl }}"><x-icon name="settings" />{{ __('Settings') }}</a>
                                    <form method="POST" action="{{ route('admin.extensions.toggle', $slug) }}">
                                        @csrf
                                        <button class="btn btn-sm" type="submit">
                                            <x-icon name="power" />{{ $item['state'] === ExtensionOverview::STATE_OFF ? __('Switch on') : __('Switch off') }}
                                        </button>
                                    </form>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>

    @if ($tab === 'registrars' && $may[ExtensionManifest::TYPE_REGISTRAR])
        <p class="muted" style="font-size:.85rem;margin-top:12px">{{ __('Each domain extension uses the registrar chosen in its domain price.') }} <a href="{{ route('admin.settings.tlds.index') }}">{{ __('Open domain prices') }}</a></p>
    @endif
</x-layouts.admin>
