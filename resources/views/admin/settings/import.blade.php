<x-layouts.admin :title="__('Import from WHMCS')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    @php
        $state = $status['state'] ?? null;
        $stateTone = ['running' => 'info', 'done' => 'good', 'failed' => 'crit', 'cancelled' => 'warn'][$state] ?? null;
        $stateLabel = ['running' => __('Running'), 'done' => __('Finished'), 'failed' => __('Stopped with an error'), 'cancelled' => __('Stopped')][$state] ?? null;
    @endphp

    <div class="grid-2">
        <div style="display:grid;gap:14px">
            <form method="POST" action="{{ route('admin.settings.import.update') }}" class="card" style="display:grid;gap:1.1rem">
                @csrf
                @method('PUT')
                <div>
                    <h2 style="font-size:1.05rem">{{ __('1. Connect to the WHMCS database') }}</h2>
                    <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Use the details from WHMCS\'s configuration.php. If WHMCS is on another server, allow this server to connect to its MySQL (in cPanel: Remote MySQL).') }}</p>
                </div>
                <div class="form-grid">
                    <x-input name="host" :label="__('Host')" :value="$connection['host'] ?? 'localhost'" required autocomplete="off" />
                    <x-input name="port" type="number" :label="__('Port')" :value="$connection['port'] ?? 3306" />
                    <x-input name="database" :label="__('Database name')" :value="$connection['database'] ?? ''" required autocomplete="off" />
                    <x-input name="username" :label="__('Database user')" :value="$connection['username'] ?? ''" required autocomplete="off" />
                    <x-input name="password" type="password" :label="__('Database password')" :help="$hasPassword ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save and check') }}</button></div>
            </form>

            @if ($check)
                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Found in WHMCS :version', ['version' => $check['version']]) }}</h2></div>
                    <div class="table-wrap"><table class="table">
                        <tbody>
                        @foreach ($steps as $step => $label)
                            <tr><td>{{ __($label) }}</td><td class="end mono">{{ number_format($check['counts'][$step] ?? 0) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table></div>
                </section>
            @endif

            <section class="card card-flush" @if ($running) x-data x-init="setTimeout(() => location.reload(), 5000)" @endif>
                <div class="card-header">
                    <h2>{{ __('2. Import') }} @if ($stateLabel)<x-pill :tone="$stateTone" style="margin-inline-start:.4rem">{{ $stateLabel }}</x-pill>@endif</h2>
                    <div style="display:flex;gap:8px;flex-wrap:wrap">
                        @if ($running)
                            <form method="POST" action="{{ route('admin.settings.import.cancel') }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Stop') }}</button></form>
                        @else
                            <form method="POST" action="{{ route('admin.settings.import.start') }}" data-confirm="{{ __('Start the import now? Clients get the same passwords they use in WHMCS.') }}">
                                @csrf
                                <button class="btn btn-sm btn-primary" type="submit" @disabled(empty($connection['database']))>{{ $state ? __('Run again') : __('Start import') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
                @if ($state === 'failed' && ! empty($status['message']))
                    <div style="padding:0 18px 14px"><div class="flash" data-tone="crit"><span>{{ $status['message'] }}</span></div></div>
                @endif
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Step') }}</th><th class="end">{{ __('Added') }}</th><th class="end">{{ __('Updated') }}</th><th class="end">{{ __('Skipped') }}</th></tr></thead>
                    <tbody>
                    @foreach ($steps as $step => $label)
                        @php $counts = $status['counts'][$step] ?? null; @endphp
                        <tr>
                            <td>
                                {{ __($label) }}
                                @if ($running && ($status['step'] ?? null) === $step)<x-pill tone="info" style="margin-inline-start:.3rem">{{ __('Now') }}</x-pill>@endif
                            </td>
                            <td class="end mono">{{ $counts ? number_format($counts['created']) : '—' }}</td>
                            <td class="end mono">{{ $counts ? number_format($counts['updated']) : '—' }}</td>
                            <td class="end mono">{{ $counts ? number_format($counts['skipped']) : '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                @if ($running)
                    <p class="faint" style="margin:0;padding:12px 18px;font-size:.82rem">{{ __('This page updates by itself. The import runs from the cron job, so it keeps going if you close the page.') }}</p>
                @endif
            </section>
        </div>

        <aside class="card" style="display:grid;gap:.8rem;align-content:start">
            <h2 style="font-size:1.05rem">{{ __('How it works') }}</h2>
            <ul style="margin:0;padding-inline-start:1.1rem;display:grid;gap:.5rem;font-size:.9rem">
                <li>{{ __('Imported: staff, clients with their passwords, product groups, products and prices, servers, services, domain prices, domains, invoices, payments, support departments and tickets.') }}</li>
                <li>{{ __('WHMCS is only read. Nothing in WHMCS changes.') }}</li>
                <li>{{ __('You can run it again before you switch. It adds new records and updates changed ones, without making copies.') }}</li>
                <li>{{ __('Not imported: server and service passwords (WHMCS encrypts them), saved cards, addons, configurable options and custom fields.') }}</li>
                <li>{{ __('Servers are imported switched off. Enter their passwords or API tokens in Servers, then switch them on.') }}</li>
                <li>{{ __('Staff are imported switched off, so old replies keep their names. Switch on the ones who should sign in.') }}</li>
                <li>{{ __('Turn off automation in WHMCS when you switch, so clients do not get two invoices.') }}</li>
            </ul>
        </aside>
    </div>
</x-layouts.admin>
