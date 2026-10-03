<x-layouts.admin :title="__('Import')">
    <x-settings-page>

        @php
            $state = $status['state'] ?? null;
            $stateTone = ['running' => 'info', 'done' => 'good', 'failed' => 'crit', 'cancelled' => 'warn'][$state] ?? null;
            $stateLabel = ['running' => __('Running'), 'done' => __('Finished'), 'failed' => __('Stopped with an error'), 'cancelled' => __('Stopped')][$state] ?? null;
            $selected = old('source', $connection['source'] ?? 'whmcs');
            $sourceInfo = collect($sources)->map(fn (string $class): array => ['file' => $class::configFile(), 'key' => $class::keyHelp() !== null ? __($class::keyHelp()) : null])->all();
            $problemTone = ['error' => 'crit', 'warning' => 'warn', 'info' => ''];
            $blocked = $preview && collect($preview['problems'])->contains('level', 'error');
        @endphp

        <div class="grid-2">
            <div style="display:grid;gap:14px">
                <form method="POST" action="{{ route('admin.settings.import.update') }}" class="card" style="display:grid;gap:1.1rem" x-data="{ source: @js($selected), info: @js($sourceInfo) }">
                    @csrf
                    @method('PUT')
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('1. Connect to the database') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">
                            {{ __('Use the database details from the other system\'s settings file:') }} <code x-text="info[source].file">{{ $sourceInfo[$selected]['file'] ?? '' }}</code>.
                            {{ __('If it is on another server, allow this server to connect to its MySQL (in cPanel: Remote MySQL).') }}
                        </p>
                    </div>
                    <div class="form-grid">
                        <x-select name="source" :label="__('Import from')" :options="collect($sources)->map(fn ($class) => $class::name())->all()" :value="$selected" x-model="source" required />
                        <x-input name="host" :label="__('Host')" :value="$connection['host'] ?? 'localhost'" required autocomplete="off" />
                        <x-input name="port" type="number" :label="__('Port')" :value="$connection['port'] ?? 3306" />
                        <x-input name="database" :label="__('Database name')" :value="$connection['database'] ?? ''" required autocomplete="off" />
                        <x-input name="username" :label="__('Database user')" :value="$connection['username'] ?? ''" required autocomplete="off" />
                        <x-input name="password" type="password" :label="__('Database password')" :help="$hasPassword ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
                        <x-input name="prefix" :label="__('Table prefix')" :value="$connection['prefix'] ?? ''" :help="__('Only if the tables start with a prefix, for example nb_.')" autocomplete="off" />
                        <x-checkbox name="tls" :label="__('Use an encrypted connection (TLS)')" :checked="$connection['tls'] ?? true" :help="__('Used when the database is on another server. Untick it only if that server has no TLS.')" />
                        <x-input name="ssl_ca" :label="__('CA file of the database server')" :value="$connection['ssl_ca'] ?? ''" :help="__('Optional. Only when the database server uses its own certificate: the path to its CA file on this server.')" autocomplete="off" />
                        <div class="field" x-show="info[source].key" @if (! ($sourceInfo[$selected]['key'] ?? null)) style="display:none" @endif>
                            <x-input name="key" type="password" :label="__('Encryption key')" autocomplete="off" />
                            <p class="help" style="margin-top:.3rem"><span x-text="info[source].key">{{ $sourceInfo[$selected]['key'] ?? '' }}</span> @if ($hasKey){{ __('Saved. Leave empty to keep it.') }}@endif</p>
                        </div>
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save and check') }}</button></div>
                </form>

                @if ($preview)
                    <section class="card card-flush">
                        <div class="card-header">
                            <h2>{{ __('2. Dry run') }} <span class="muted" style="font-weight:400;font-size:.85rem">{{ $preview['system'] }} {{ $preview['version'] }}</span></h2>
                            <form method="POST" action="{{ route('admin.settings.import.preview') }}">@csrf<button class="btn btn-sm" type="submit" @disabled($running)>{{ __('Check again') }}</button></form>
                        </div>
                        <p class="muted" style="margin:0;padding:0 18px 10px;font-size:.85rem">{{ __('Nothing was changed. This is what the import would do, checked :time.', ['time' => \Illuminate\Support\Carbon::parse($preview['checked_at'])->diffForHumans()]) }}</p>
                        <div class="table-wrap"><table class="table">
                            <thead><tr><th>{{ __('Step') }}</th><th class="end">{{ __('Found') }}</th><th class="end">{{ __('New') }}</th><th class="end">{{ __('Imported before') }}</th></tr></thead>
                            <tbody>
                            @foreach ($preview['steps'] as $step)
                                <tr>
                                    <td>{{ __($step['label']) }}</td>
                                    <td class="end mono">{{ number_format($step['total']) }}</td>
                                    <td class="end mono">{{ number_format($step['new']) }}</td>
                                    <td class="end mono">{{ number_format($step['existing']) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                        @if ($preview['problems'] !== [])
                            <div style="border-top:1px solid var(--nb-line)">
                                @foreach ($preview['problems'] as $problem)
                                    <div class="health-check">
                                        <div class="health-check-head" style="cursor:default;align-items:flex-start">
                                            <span class="health-dot" @if ($problemTone[$problem['level']]) data-tone="{{ $problemTone[$problem['level']] }}" @endif aria-hidden="true">{{ $problem['level'] === 'info' ? 'i' : '!' }}</span>
                                            <span class="grow">
                                                {{ __($problem['text'], array_map(fn ($value) => is_int($value) ? number_format($value) : $value, $problem['params'])) }}
                                                @if ($problem['examples'] !== [])
                                                    <span class="faint" style="display:block;font-size:.8rem">{{ __('For example: :list', ['list' => implode(', ', $problem['examples'])]) }}</span>
                                                @endif
                                            </span>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif

                <section class="card card-flush" @if ($running) x-data x-init="setTimeout(() => location.reload(), 5000)" @endif>
                    <div class="card-header">
                        <h2>{{ $preview ? __('3. Import') : __('2. Import') }} @if ($stateLabel)<x-pill :tone="$stateTone" style="margin-inline-start:.4rem">{{ $stateLabel }}</x-pill>@endif</h2>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            @if ($running)
                                <form method="POST" action="{{ route('admin.settings.import.cancel') }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Stop') }}</button></form>
                            @else
                                <form method="POST" action="{{ route('admin.settings.import.start') }}" data-confirm="{{ __('Start the import now? The other system is only read; nothing in it changes.') }}">
                                    @csrf
                                    <button class="btn btn-sm btn-primary" type="submit" @disabled(empty($connection['database']) || $blocked)>{{ $state ? __('Run again') : __('Start import') }}</button>
                                </form>
                            @endif
                        </div>
                    </div>
                    @if ($blocked && ! $running)
                        <div style="padding:0 18px 14px"><div class="flash" data-tone="crit"><span>{{ __('Fix the problems marked in red in the dry run first, then check again.') }}</span></div></div>
                    @endif
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
                    @if (! empty($status['errors']))
                        <details style="border-top:1px solid var(--nb-line);padding:12px 18px">
                            <summary style="cursor:pointer;font-size:.88rem">{{ __('Rows that could not be imported (:count)', ['count' => count($status['errors'])]) }}</summary>
                            <div class="table-wrap" style="margin-top:.6rem"><table class="table">
                                <thead><tr><th>{{ __('Step') }}</th><th>{{ __('ID') }}</th><th>{{ __('Reason') }}</th></tr></thead>
                                <tbody>
                                @foreach ($status['errors'] as $error)
                                    <tr><td>{{ __($steps[$error['step']] ?? $error['step']) }}</td><td class="mono">{{ $error['id'] }}</td><td style="white-space:normal">{{ $error['error'] }}</td></tr>
                                @endforeach
                                </tbody>
                            </table></div>
                        </details>
                    @endif
                    @if ($running)
                        <p class="faint" style="margin:0;padding:12px 18px;font-size:.82rem">{{ __('This page updates by itself. The import runs from the cron job, so it keeps going if you close the page.') }}</p>
                    @endif
                </section>
            </div>

            <aside class="card" style="display:grid;gap:.8rem;align-content:start">
                <h2 style="font-size:1.05rem">{{ __('How it works') }}</h2>
                <ul style="margin:0;padding-inline-start:1.1rem;display:grid;gap:.5rem;font-size:.9rem">
                    <li>{{ __('Import from :systems.', ['systems' => collect($sources)->map(fn ($class) => $class::name())->join(', ', ' '.__('or').' ')]) }}</li>
                    <li>{{ __('Imported: staff, clients with their passwords, products and prices, servers, services, domains, invoices, payments, wallet credit and tickets.') }}</li>
                    <li>{{ __('The other system is only read. Nothing in it changes.') }}</li>
                    <li>{{ __('"Save and check" makes a dry run: it shows what would come across and any problems, without changing anything.') }}</li>
                    <li>{{ __('You can run it again before you switch. It adds new records and updates changed ones, without making copies.') }}</li>
                    <li>{{ __('Clients keep their passwords. Older kinds of password are checked at the first sign-in and then replaced.') }}</li>
                    <li>{{ __('Not imported: saved cards, addons, configurable options and custom fields.') }}</li>
                    <li>{{ __('Servers and staff are imported switched off. Check the servers, then switch on the ones Nuvabill should use.') }}</li>
                    <li>{{ __('Turn off automation in the other system when you switch, so clients do not get two invoices.') }}</li>
                </ul>
            </aside>
        </div>
    </x-settings-page>
</x-layouts.admin>
