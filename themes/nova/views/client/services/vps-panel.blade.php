{{--
    A server panel on the client's service page, used by VPS modules (Virtualizor, Proxmox and others).
    Every button posts to Nuvabill, which calls the control panel with the admin key: the client never sees it.
    Values ($panel), all optional; missing ones are skipped:
    - hostname, os, ips (list), state (running|stopped|suspended|unknown) and cpu (%)
    - ram, disk, bandwidth ({used, total, unit}) and network ({in, out} in bytes a second)
    - templates (os id => name) for a reinstall, and recipe (true: a reinstall may run the plan's recipe again)
    - login: the label of a button that opens the module's own panel through the login route
    - console: "panel" when the console is in that panel, so no VNC details are offered
    $result is the last action's data: pending (a power action) or vnc ({ip, port, password}).
--}}
@php
    $can = fn (string $action): bool => array_key_exists($action, $actions);
    $post = fn (string $action): string => route('client.services.panel', [$service, $action]);
    $labels = ['running' => __('Running'), 'stopped' => __('Stopped'), 'suspended' => __('Suspended'), 'unknown' => __('Unknown')];
    $tones = ['running' => 'good', 'stopped' => 'crit', 'suspended' => 'warn'];
    $state = array_key_exists((string) ($panel['state'] ?? ''), $labels) ? $panel['state'] : 'unknown';
    $pendingLabels = ['start' => __('Starting…'), 'stop' => __('Shutting down…'), 'restart' => __('Restarting…'), 'poweroff' => __('Powering off…')];
    $pending = is_string($result['pending'] ?? null) && array_key_exists($result['pending'], $pendingLabels) ? $result['pending'] : null;
    // A power action the server has already finished needs no waiting.
    $pending = $pending !== null && $state !== ['start' => 'running', 'restart' => 'running', 'stop' => 'stopped', 'poweroff' => 'stopped'][$pending] ? $pending : null;
    $power = array_values(array_filter(['start', 'restart', 'stop', 'poweroff'], $can));
    $shows = fn (string $action): bool => match ($state) {
        'unknown' => true,
        'suspended' => false,
        default => $action === 'start' ? $state === 'stopped' : $state === 'running',
    };
    $confirm = [
        'restart' => __('Restart the server now?'),
        'stop' => __('Shut down the server now? Websites and apps on it will stop.'),
        'poweroff' => __('Power off without a clean shutdown? Only use this if the server does not respond.'),
    ];
    $vnc = is_array($result['vnc'] ?? null) ? $result['vnc'] : null;
    $login = is_string($panel['login'] ?? null) && $panel['login'] !== '' ? $panel['login'] : null;
    $console = ($panel['console'] ?? 'vnc') === 'panel' ? 'panel' : 'vnc';
    $ips = array_values(array_filter((array) ($panel['ips'] ?? []), 'is_string'));
    $rate = function (mixed $bytes): string {
        $bytes = max(0, (float) $bytes);
        $units = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
        $unit = 0;

        while ($bytes >= 1024 && $unit < 3) {
            $bytes /= 1024;
            $unit++;
        }

        return number_format($bytes, $unit === 0 ? 0 : 1).' '.$units[$unit];
    };
    $config = [
        'state' => $state,
        'pending' => $pending,
        'statusUrl' => route('client.services.panel-status', $service),
        'labels' => $labels,
        'pendingLabels' => $pendingLabels,
        'messages' => [
            'failed' => __('That did not work. Reload the page and try again.'),
            'slow' => __('The server has not changed its state yet. Check again in a minute.'),
            'stillRunning' => __('The server is still running. If it does not shut down, use Power off.'),
            'reached' => [
                'running' => __('The server is running.'),
                'stopped' => __('The server is stopped.'),
                'suspended' => __('The server is suspended.'),
                'unknown' => __('The server did not report its state. Try again in a minute.'),
            ],
        ],
    ];
@endphp

<section class="card vps-panel" aria-labelledby="vps-panel-title" x-data="vpsPanel(@js($config))">
    <div class="vps-panel-head">
        <div class="vps-panel-name">
            <h2 id="vps-panel-title">{{ __('Manage your server') }}</h2>
            @if (empty($panel['error']) && (filled($panel['hostname'] ?? null) || filled($panel['os'] ?? null)))
                <p class="muted vps-panel-meta">
                    @if (filled($panel['hostname'] ?? null))<span class="mono" dir="ltr">{{ $panel['hostname'] }}</span>@endif
                    @if (filled($panel['os'] ?? null))<span><bdi>{{ $panel['os'] }}</bdi></span>@endif
                </p>
            @endif
        </div>
        @if (empty($panel['error']))
            <div class="vps-panel-side">
                <span class="pill" @if ($pending) data-tone="info" @elseif (isset($tones[$state])) data-tone="{{ $tones[$state] }}" @endif :data-tone="tone" x-text="label">{{ $pending ? $pendingLabels[$pending] : $labels[$state] }}</span>
                @if ($login)
                    <form method="POST" action="{{ route('client.services.login', $service) }}" target="_blank" rel="noopener noreferrer">
                        @csrf
                        <button class="btn btn-primary" type="submit"><x-icon name="external" />{{ $login }}<span class="sr-only"> {{ __('(opens in a new tab)') }}</span></button>
                    </form>
                @endif
            </div>
        @endif
    </div>

    @if (! empty($panel['error']))
        <div class="flash" data-tone="warn"><span>{{ $panel['error'] }}</span></div>
    @else
        @if ($power !== [])
            <div class="vps-power">
                {{-- A suspended server has no power button to show, so the whole bar is hidden. --}}
                <form method="POST" action="{{ $post($power[0]) }}" x-on:submit="power($event)" x-show="state !== 'suspended'" @if ($state === 'suspended') style="display: none" @endif>
                    @csrf
                    <div class="vps-power-group" role="group" aria-label="{{ __('Power') }}">
                        @foreach ($power as $action)
                            <button
                                type="submit"
                                formaction="{{ $post($action) }}"
                                data-action="{{ $action }}"
                                @isset($confirm[$action]) data-confirm="{{ $confirm[$action] }}" @endisset
                                class="btn btn-sm @if ($action === 'start') btn-primary @elseif ($action === 'poweroff') btn-danger @endif"
                                x-show="shows('{{ $action }}')"
                                :disabled="locked('{{ $action }}')"
                                @unless ($shows($action)) style="display: none" @endunless
                            ><x-icon :name="$action === 'restart' ? 'refresh' : 'power'" />{{ $actions[$action] }}</button>
                        @endforeach
                        <a class="btn btn-sm btn-ghost" href="{{ route('client.services.show', $service) }}" x-show="state === 'unknown' && ! pending" x-on:click.prevent="refresh()" :aria-disabled="busy ? 'true' : 'false'" @unless ($state === 'unknown' && ! $pending) style="display: none" @endunless><x-icon name="refresh" />{{ __('Refresh status') }}</a>
                    </div>
                </form>
                <p class="vps-message" role="status" aria-live="polite"><span class="vps-spinner" x-show="busy" style="display: none" aria-hidden="true"></span><span x-text="message"></span></p>
                @if ($can('stop') && $can('poweroff'))
                    <p class="muted vps-note" x-show="shows('stop')" @unless ($shows('stop')) style="display: none" @endunless>{{ __('Shut down asks the system to stop cleanly, so it needs ACPI support in the operating system. Power off cuts the power at once, like pulling the plug: use it only when Shut down does not work.') }}</p>
                @endif
                <p class="muted vps-note" x-show="state === 'suspended'" @unless ($state === 'suspended') style="display: none" @endunless>{{ __('This server is suspended. Contact support to switch it back on.') }}</p>
            </div>
        @endif

        @php
            $meters = array_filter(['ram' => __('Memory'), 'disk' => __('Disk'), 'bandwidth' => __('Bandwidth this month')], fn (string $label, string $key): bool => is_array($panel[$key] ?? null), ARRAY_FILTER_USE_BOTH);
            $network = is_array($panel['network'] ?? null) ? $panel['network'] : null;
        @endphp
        @if (isset($panel['cpu']) || $meters !== [] || $network)
            <div class="usage-grid">
                @if (isset($panel['cpu']))
                    <div class="usage">
                        <div class="usage-label"><span>{{ __('CPU') }}</span><span dir="ltr">{{ number_format((float) $panel['cpu'], 1) }}%</span></div>
                        <div class="usage-bar" aria-hidden="true" @if ($panel['cpu'] >= 90) data-tone="crit" @elseif ($panel['cpu'] >= 75) data-tone="warn" @endif><span style="width:{{ min(100, max(0, (float) $panel['cpu'])) }}%"></span></div>
                    </div>
                @endif
                @foreach ($meters as $key => $label)
                    @php
                        $meter = $panel[$key];
                        $used = (float) ($meter['used'] ?? 0);
                        $total = (float) ($meter['total'] ?? 0);
                        $percent = $total > 0 ? min(100, round($used / $total * 100, 1)) : 0;
                    @endphp
                    <div class="usage">
                        <div class="usage-label">
                            <span>{{ $label }}</span>
                            <span dir="ltr">{{ number_format($used, $used < 10 ? 1 : 0) }} / {{ $total > 0 ? number_format($total).' '.($meter['unit'] ?? '') : __('Unlimited') }}</span>
                        </div>
                        <div class="usage-bar" aria-hidden="true" @if ($percent >= 90) data-tone="crit" @elseif ($percent >= 75) data-tone="warn" @endif><span style="width:{{ $percent }}%"></span></div>
                    </div>
                @endforeach
                @if ($network)
                    <div class="usage">
                        <div class="usage-label"><span>{{ __('Network now') }}</span></div>
                        <div class="vps-net">
                            <span>{{ __('Incoming') }} <strong class="num" dir="ltr">{{ $rate($network['in'] ?? 0) }}</strong></span>
                            <span>{{ __('Outgoing') }} <strong class="num" dir="ltr">{{ $rate($network['out'] ?? 0) }}</strong></span>
                        </div>
                    </div>
                @endif
            </div>
        @endif

        @if ($ips !== [])
            <div class="vps-section">
                <h3 class="eyebrow">{{ trans_choice('IP address|IP addresses', count($ips)) }}</h3>
                <ul class="vps-ips">
                    @foreach ($ips as $ip)
                        <li class="vps-ip">
                            <span class="mono" dir="ltr" id="vps-ip-{{ $loop->index }}">{{ $ip }}</span>
                            @if (str_contains($ip, ':'))<span class="pill">IPv6</span>@endif
                            <button type="button" class="btn btn-sm btn-ghost" data-copy="{{ $ip }}" data-copied="{{ __('Copied') }}" aria-describedby="vps-ip-{{ $loop->index }}">{{ __('Copy') }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($vnc)
            <div class="flash" data-tone="info" style="display:grid;gap:.3rem">
                <strong>{{ __('VNC console') }}</strong>
                <span>{{ __('Connect with a VNC app such as TigerVNC or RealVNC.') }}</span>
                <span class="mono" dir="ltr">{{ $vnc['ip'] ?? '' }}:{{ $vnc['port'] ?? '' }}</span>
                @if (($vnc['password'] ?? '') !== '')<span>{{ __('Password') }}: <span class="mono">{{ $vnc['password'] }}</span></span>@endif
            </div>
        @endif

        @if ($can('hostname') || $can('password') || ($can('reinstall') && ! empty($panel['templates'])) || ($can('vnc') && $console === 'vnc' && ! $vnc) || ($login && $console === 'panel'))
            <div class="vps-section">
                <h3 class="eyebrow">{{ __('Settings') }}</h3>
                <div class="vps-tools">
                    @if ($can('hostname'))
                        <details class="vps-tool">
                            <summary>{{ $actions['hostname'] }}</summary>
                            <form method="POST" action="{{ $post('hostname') }}" class="vps-tool-form" x-on:submit="lock($event)">
                                @csrf
                                <x-input name="hostname" id="vps-hostname" :label="__('New hostname')" :value="$panel['hostname'] ?? ''" required autocomplete="off" spellcheck="false" dir="ltr" placeholder="server.example.com" />
                                <div><button class="btn btn-sm btn-primary" type="submit">{{ __('Save hostname') }}</button></div>
                            </form>
                        </details>
                    @endif

                    @if ($can('password'))
                        <details class="vps-tool">
                            <summary>{{ $actions['password'] }}</summary>
                            <form method="POST" action="{{ $post('password') }}" class="vps-tool-form" x-on:submit="lock($event)">
                                @csrf
                                <x-input name="password" type="password" id="vps-root-password" :label="__('New root password')" :help="__('At least 10 characters, with letters and numbers.')" required minlength="10" autocomplete="new-password" />
                                <div><button class="btn btn-sm btn-primary" type="submit">{{ __('Change password') }}</button></div>
                            </form>
                        </details>
                    @endif

                    @if ($can('reinstall') && ! empty($panel['templates']))
                        <details class="vps-tool">
                            <summary>{{ $actions['reinstall'] }}</summary>
                            <form method="POST" action="{{ $post('reinstall') }}" class="vps-tool-form" data-confirm="{{ __('Reinstall the operating system? Everything on the server is erased. This cannot be undone.') }}" x-on:submit="lock($event)">
                                @csrf
                                <div class="flash" data-tone="crit"><span>{{ __('A reinstall erases all files, websites and databases on the server.') }}</span></div>
                                <x-select name="os_id" id="vps-os" :label="__('Operating system')" :options="$panel['templates']" :placeholder="__('Choose…')" required />
                                <x-input name="password" type="password" id="vps-reinstall-password" :label="__('New root password')" :help="__('At least 10 characters, with letters and numbers.')" required minlength="10" autocomplete="new-password" />
                                @if (! empty($panel['recipe']))
                                    <x-checkbox name="run_recipe" id="vps-run-recipe" :label="__('Run the setup script of your plan again')" :help="__('It installs the same apps as on your first setup.')" />
                                @endif
                                <div><button class="btn btn-sm btn-danger" type="submit">{{ __('Reinstall now') }}</button></div>
                            </form>
                        </details>
                    @endif

                    @if ($can('vnc') && $console === 'vnc' && ! $vnc)
                        <form method="POST" action="{{ $post('vnc') }}">@csrf<button class="btn btn-sm" type="submit">{{ $actions['vnc'] }}</button></form>
                    @endif
                </div>
                @if ($login && $console === 'panel')
                    <p class="muted vps-note">{{ __('Need the console? Open the panel with the button at the top: it has a console in your browser.') }}</p>
                @endif
            </div>
        @endif
    @endif
</section>
