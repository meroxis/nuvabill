{{--
    A server panel on the client's service page, used by VPS modules (Virtualizor, Proxmox).
    Every button posts to Nuvabill, which calls the control panel with the admin key: the client never sees it.
    Values ($panel): hostname, os, state (running|stopped|suspended), cpu (%), ram/disk/bandwidth
    ({used, total, unit}), ips (list) and templates (os id => name). Missing values are skipped.
--}}
@php
    $can = fn (string $action): bool => array_key_exists($action, $actions);
    $post = fn (string $action): string => route('client.services.panel', [$service, $action]);
    $state = $panel['state'] ?? 'unknown';
    $stateLabel = ['running' => __('Running'), 'stopped' => __('Stopped'), 'suspended' => __('Suspended')][$state] ?? __('Unknown');
    $stateTone = ['running' => 'good', 'stopped' => 'crit', 'suspended' => 'warn'][$state] ?? null;
    $vnc = is_array($result['vnc'] ?? null) ? $result['vnc'] : null;
@endphp

<section class="card vps-panel" aria-labelledby="vps-panel-title">
    <div class="vps-panel-head">
        <div>
            <h2 id="vps-panel-title" style="font-size:1.05rem">{{ __('Manage your server') }}</h2>
            @if (empty($panel['error']))
                <p class="muted" style="margin:.25rem 0 0;font-size:.88rem">
                    <span class="mono">{{ $panel['hostname'] ?? '' }}</span>@if (! empty($panel['os'])) · {{ $panel['os'] }}@endif
                </p>
            @endif
        </div>
        @if (empty($panel['error']))
            <x-pill :tone="$stateTone">{{ $stateLabel }}</x-pill>
        @endif
    </div>

    @if (! empty($panel['error']))
        <div class="flash" data-tone="warn"><span>{{ $panel['error'] }}</span></div>
    @else
        <div class="vps-power">
            @if ($state !== 'running' && $can('start'))
                <form method="POST" action="{{ $post('start') }}">@csrf<button class="btn btn-primary btn-sm" type="submit"><x-icon name="power" />{{ $actions['start'] }}</button></form>
            @endif
            @if ($state === 'running')
                @if ($can('restart'))
                    <form method="POST" action="{{ $post('restart') }}" data-confirm="{{ __('Restart the server now?') }}">@csrf<button class="btn btn-sm" type="submit"><x-icon name="refresh" />{{ $actions['restart'] }}</button></form>
                @endif
                @if ($can('stop'))
                    <form method="POST" action="{{ $post('stop') }}" data-confirm="{{ __('Shut down the server now? Websites and apps on it will stop.') }}">@csrf<button class="btn btn-sm" type="submit">{{ $actions['stop'] }}</button></form>
                @endif
                @if ($can('poweroff'))
                    <form method="POST" action="{{ $post('poweroff') }}" data-confirm="{{ __('Power off without a clean shutdown? Only use this if the server does not respond.') }}">@csrf<button class="btn btn-sm btn-danger" type="submit">{{ $actions['poweroff'] }}</button></form>
                @endif
            @endif
        </div>

        <div class="usage-grid">
            @if (isset($panel['cpu']))
                <div class="usage">
                    <div class="usage-label"><span>{{ __('CPU') }}</span><span>{{ number_format($panel['cpu'], 1) }}%</span></div>
                    <div class="usage-bar"><span style="width:{{ min(100, max(0, $panel['cpu'])) }}%"></span></div>
                </div>
            @endif
            @foreach (['ram' => __('Memory'), 'disk' => __('Disk'), 'bandwidth' => __('Bandwidth this month')] as $key => $label)
                @php
                    $meter = $panel[$key] ?? null;
                    $total = (float) ($meter['total'] ?? 0);
                    $percent = $total > 0 ? min(100, round($meter['used'] / $total * 100, 1)) : 0;
                @endphp
                @if ($meter)
                    <div class="usage">
                        <div class="usage-label">
                            <span>{{ $label }}</span>
                            <span>{{ number_format($meter['used'], $meter['used'] < 10 ? 1 : 0) }} / {{ $total > 0 ? number_format($total).' '.$meter['unit'] : __('Unlimited') }}</span>
                        </div>
                        <div class="usage-bar" @if ($percent >= 90) data-tone="crit" @elseif ($percent >= 75) data-tone="warn" @endif><span style="width:{{ $percent }}%"></span></div>
                    </div>
                @endif
            @endforeach
        </div>

        @if (! empty($panel['ips']))
            <dl class="dl">
                <dt>{{ trans_choice('IP address|IP addresses', count($panel['ips'])) }}</dt>
                <dd class="mono">
                    @foreach ($panel['ips'] as $ip)
                        <span style="display:inline-flex;gap:.4rem;align-items:center">{{ $ip }} <button type="button" class="btn btn-sm" data-copy="{{ $ip }}">{{ __('Copy') }}</button></span>@if (! $loop->last)<br>@endif
                    @endforeach
                </dd>
            </dl>
        @endif

        @if ($vnc)
            <div class="flash" data-tone="info" style="display:grid;gap:.3rem">
                <strong>{{ __('VNC console') }}</strong>
                <span>{{ __('Connect with a VNC app such as TigerVNC or RealVNC.') }}</span>
                <span class="mono">{{ $vnc['ip'] }}:{{ $vnc['port'] }}</span>
                @if ($vnc['password'] !== '')<span>{{ __('Password') }}: <span class="mono">{{ $vnc['password'] }}</span></span>@endif
            </div>
        @endif

        <div class="vps-tools">
            @if ($can('hostname'))
                <details class="vps-tool">
                    <summary>{{ $actions['hostname'] }}</summary>
                    <form method="POST" action="{{ $post('hostname') }}" class="vps-tool-form">
                        @csrf
                        <x-input name="hostname" id="vps-hostname" :label="__('New hostname')" :value="$panel['hostname'] ?? ''" required autocomplete="off" spellcheck="false" placeholder="server.example.com" />
                        <div><button class="btn btn-sm btn-primary" type="submit">{{ __('Save hostname') }}</button></div>
                    </form>
                </details>
            @endif

            @if ($can('password'))
                <details class="vps-tool">
                    <summary>{{ $actions['password'] }}</summary>
                    <form method="POST" action="{{ $post('password') }}" class="vps-tool-form">
                        @csrf
                        <x-input name="password" type="password" id="vps-root-password" :label="__('New root password')" :help="__('At least 10 characters, with letters and numbers.')" required minlength="10" autocomplete="new-password" />
                        <div><button class="btn btn-sm btn-primary" type="submit">{{ __('Change password') }}</button></div>
                    </form>
                </details>
            @endif

            @if ($can('reinstall') && ! empty($panel['templates']))
                <details class="vps-tool">
                    <summary>{{ $actions['reinstall'] }}</summary>
                    <form method="POST" action="{{ $post('reinstall') }}" class="vps-tool-form" data-confirm="{{ __('Reinstall the operating system? Everything on the server is erased. This cannot be undone.') }}">
                        @csrf
                        <div class="flash" data-tone="crit"><span>{{ __('A reinstall erases all files, websites and databases on the server.') }}</span></div>
                        <x-select name="os_id" id="vps-os" :label="__('Operating system')" :options="$panel['templates']" :placeholder="__('Choose…')" required />
                        <x-input name="password" type="password" id="vps-reinstall-password" :label="__('New root password')" :help="__('At least 10 characters, with letters and numbers.')" required minlength="10" autocomplete="new-password" />
                        <div><button class="btn btn-sm btn-danger" type="submit">{{ __('Reinstall now') }}</button></div>
                    </form>
                </details>
            @endif

            @if ($can('vnc') && ! $vnc)
                <form method="POST" action="{{ $post('vnc') }}">@csrf<button class="btn btn-sm" type="submit">{{ $actions['vnc'] }}</button></form>
            @endif
        </div>
    @endif
</section>
