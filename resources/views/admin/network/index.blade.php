<x-layouts.admin :title="__('Network status')">
    <div class="page-head">
        <div>
            <h1>{{ __('Network status') }}</h1>
            <p>{{ __('Tell clients about problems and planned maintenance. Nuvabill checks your servers every 5 minutes.') }}</p>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('admin.network.incidents.create', ['kind' => 'maintenance']) }}"><x-icon name="clock" />{{ __('Plan maintenance') }}</a>
            <a class="btn btn-primary" href="{{ route('admin.network.incidents.create') }}"><x-icon name="alert" />{{ __('Report an issue') }}</a>
        </div>
    </div>

    @include('admin.support.nav')

    <div class="grid-2" style="grid-template-columns:minmax(0,2fr) minmax(0,1fr);align-items:start">
        <div style="display:grid;gap:14px">
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Open') }}</h2></div>
                @if ($open->isEmpty())
                    <div class="empty"><strong>{{ __('Nothing open') }}</strong>{{ __('Clients see “All services are running” unless a server you show is down.') }}</div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Title') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Last update') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($open as $incident)
                            <tr>
                                <td><a class="row-link" href="{{ route('admin.network.incidents.show', $incident) }}">{{ $incident->title }}</a>@if ($incident->isMaintenance() && $incident->starts_at)<div class="faint" style="font-size:.8rem">{{ $incident->starts_at->translatedFormat('d M Y H:i') }}@if ($incident->ends_at) – {{ $incident->ends_at->translatedFormat('d M H:i') }}@endif</div>@endif</td>
                                <td>{{ \App\Models\NetworkIncident::kindLabel($incident->kind) }}</td>
                                <td style="white-space:nowrap">{{ ($incident->updates->first()?->created_at ?? $incident->created_at)->diffForHumans() }}</td>
                                <td><x-status :value="$incident->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            <form method="POST" action="{{ route('admin.network.servers') }}" class="card card-flush">
                @csrf
                @method('PUT')
                <div class="card-header">
                    <h2>{{ __('Servers') }}</h2>
                    <button class="btn btn-sm" type="submit" form="network-check">{{ __('Check now') }}</button>
                </div>
                @if ($servers->isEmpty())
                    <div class="empty">{{ __('No servers yet. Servers you add under Servers show up here.') }}</div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Server') }}</th><th>{{ __('Now') }}</th><th class="end">{{ __('Uptime (30 days)') }}</th><th>{{ __('Show on the status page') }}</th></tr></thead>
                        <tbody>
                        @foreach ($servers as $server)
                            <tr>
                                <td>{{ $server->name }}<div class="faint mono" style="font-size:.78rem">{{ $server->hostname }}</div></td>
                                <td>
                                    @if (! $server->is_active)
                                        <x-pill>{{ __('Off') }}</x-pill>
                                    @elseif ($server->status_up === true)
                                        <x-pill tone="good">{{ __('Online') }}</x-pill>
                                    @elseif ($server->status_up === false)
                                        <x-pill tone="crit">{{ __('Offline') }}</x-pill>
                                    @else
                                        <x-pill>{{ __('Not checked yet') }}</x-pill>
                                    @endif
                                    @if ($server->status_checked_at)<div class="faint" style="font-size:.78rem">{{ $server->status_checked_at->diffForHumans() }}</div>@endif
                                </td>
                                <td class="end num">{{ isset($uptime[$server->id]) ? number_format($uptime[$server->id], 2).'%' : '—' }}</td>
                                <td>
                                    <input type="hidden" name="servers[{{ $server->id }}][public]" value="0">
                                    <label class="check"><input type="checkbox" name="servers[{{ $server->id }}][public]" value="1" @checked($server->status_public)> {{ __('Show') }}</label>
                                    <input class="input" style="margin-top:6px;max-width:240px" type="text" maxlength="100" name="servers[{{ $server->id }}][name]" value="{{ $server->status_name }}" placeholder="{{ $server->name }}" aria-label="{{ __('Name on the status page for :server', ['server' => $server->name]) }}">
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    <div style="padding:12px 16px;display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                        <button class="btn btn-primary btn-sm" type="submit">{{ __('Save') }}</button>
                        <span class="help" style="margin:0">{{ __('The status page shows only the name, never the host name or IP address.') }}</span>
                    </div>
                @endif
            </form>
            <form method="POST" action="{{ route('admin.network.check') }}" id="network-check">@csrf</form>

            @if ($recent->isNotEmpty())
                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Resolved') }}</h2></div>
                    <div class="table-wrap"><table class="table">
                        <tbody>
                        @foreach ($recent as $incident)
                            <tr>
                                <td><a class="row-link" href="{{ route('admin.network.incidents.show', $incident) }}">{{ $incident->title }}</a></td>
                                <td>{{ \App\Models\NetworkIncident::kindLabel($incident->kind) }}</td>
                                <td class="num" style="white-space:nowrap">{{ $incident->resolved_at->translatedFormat('d M Y H:i') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </section>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.network.settings') }}" class="card" style="display:grid;gap:1rem">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Settings') }}</h2></div>
            <x-checkbox name="enabled" :label="__('Show the network status page')" :help="__('Clients also see open issues for their servers on their dashboard.')" :checked="setting('status.enabled')" />
            <x-checkbox name="checks" :label="__('Check servers every 5 minutes')" :help="__('Nuvabill connects to the control panel port of each switched-on server.')" :checked="setting('status.checks')" />
            <x-checkbox name="alerts" :label="__('Email me when a server goes down')" :help="__('To your company email, and again when it is back.')" :checked="setting('status.alerts')" />
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                @if (setting('status.enabled'))
                    <a class="btn" href="{{ route('network.status') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open') }}</a>
                @endif
            </div>
        </form>
    </div>
</x-layouts.admin>
