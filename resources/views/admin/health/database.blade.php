<x-layouts.admin :title="__('Site health')">
    @include('admin.health.partials.head', ['run' => $run, 'allChecks' => route('admin.health.checks', 'database'), 'intro' => __('How safe, healthy and tidy your database is. :product :version · :count tables.', ['product' => $server['product'], 'version' => $server['version'], 'count' => $tableCount])])
    @include('admin.health.partials.tabs')

    @php
        $oldTotal = array_sum(array_column($oldRecords, 'count'));
        $lastOptimized = $settings['database.last_optimized_at'] ? \Illuminate\Support\Carbon::parse($settings['database.last_optimized_at']) : null;
    @endphp

    <div class="kpis">
        <div class="kpi">
            <small>{{ __('Database size') }}</small>
            <b>{{ $totals['size'] ? \Illuminate\Support\Number::fileSize($totals['size']) : '—' }}</b>
            <span>{{ trans_choice(':count table|:count tables', $tableCount, ['count' => $tableCount]) }}</span>
        </div>
        <div class="kpi">
            <small>{{ __('Space you can free') }}</small>
            <b style="color:var(--nb-accent)">{{ \Illuminate\Support\Number::fileSize($totals['free']) }}</b>
            <span>{{ $totals['size'] ? __(':percent% of the database', ['percent' => (int) round($totals['free'] / $totals['size'] * 100)]) : '' }}</span>
        </div>
        <div class="kpi">
            <small>{{ __('Old records to clean up') }}</small>
            <b>{{ number_format($oldTotal) }}</b>
            <span>{{ $settings['database.cleanup_nightly'] ? __('Cleaned up every night') : __('Not cleaned up yet') }}</span>
        </div>
        <div class="kpi">
            <small>{{ __('Last optimized') }}</small>
            <b>{{ $lastOptimized ? $lastOptimized->diffForHumans() : __('Never') }}</b>
            <span>{{ $settings['database.optimize_weekly'] ? __('Every Sunday night') : __('Only when you press the button') }}</span>
        </div>
    </div>

    <div class="grid-halves">
        <div style="display:grid;gap:14px;align-content:start">
            @if ($run === null)
                <section class="card"><div class="empty"><strong>{{ __('Not checked yet') }}</strong>{{ __('Press "Check now" to check the database.') }}</div></section>
            @endif
            @foreach ($groups as $card)
                @continue($card['checks']->isEmpty())
                <section class="card card-flush">
                    <div class="card-header">
                        <h2>{{ __($card['group']->title()) }}</h2>
                        @if ($card['urgent'])
                            <x-pill tone="crit">{{ trans_choice(':count urgent|:count urgent', $card['urgent'], ['count' => $card['urgent']]) }}</x-pill>
                        @elseif ($card['warning'])
                            <x-pill tone="warn">{{ trans_choice(':count to fix|:count to fix', $card['warning'], ['count' => $card['warning']]) }}</x-pill>
                        @else
                            <x-pill tone="good">{{ __(':passed of :total passed', ['passed' => $card['passed'], 'total' => $card['counted']]) }}</x-pill>
                        @endif
                    </div>
                    @foreach ($card['checks'] as $check)
                        @include('admin.health.partials.check', ['check' => $check])
                    @endforeach
                </section>
            @endforeach
        </div>

        <div style="display:grid;gap:14px;align-content:start">
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Tables with space to free') }}</h2><span class="muted" style="font-size:.85rem">{{ __('Biggest first') }}</span></div>
                @if ($isSqlite)
                    <p class="muted" style="margin:0;padding:0 1.1rem 1rem">{{ __('SQLite keeps free space for the whole file: :size now.', ['size' => \Illuminate\Support\Number::fileSize($totals['free'])]) }}</p>
                @elseif ($tables === [])
                    <p class="muted" style="margin:0;padding:0 1.1rem 1rem">{{ __('No table has space worth freeing.') }}</p>
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('Table') }}</th><th class="num">{{ __('Size') }}</th><th class="num">{{ __('Can free') }}</th><th class="num">{{ __('Rows') }}</th></tr></thead>
                            <tbody>
                                @foreach ($tables as $table)
                                    <tr>
                                        <td class="mono">{{ $table['name'] }}</td>
                                        <td class="num">{{ \Illuminate\Support\Number::fileSize($table['size']) }}</td>
                                        <td class="num" style="color:var(--nb-accent);font-weight:700">{{ \Illuminate\Support\Number::fileSize($table['free']) }}</td>
                                        <td class="num">{{ number_format($table['rows']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
                <form method="POST" action="{{ route('admin.health.optimize') }}" class="health-actions" style="padding:.9rem 1.1rem;border-top:1px solid var(--nb-line);background:var(--nb-surface-2)" data-confirm="{{ __('Optimize the database now? A backup is made first. On a large database this can take a minute.') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">{{ __('Optimize now') }}</button>
                    <span class="muted" style="font-size:.85rem">{{ __('Rebuilds tables and refreshes their statistics, so pages stay fast. A backup is made first.') }}</span>
                </form>
            </section>

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Clean up old records') }}</h2></div>
                <form method="POST" action="{{ route('admin.health.database.settings') }}" style="display:grid;gap:.8rem;padding:0 1.1rem 1rem">
                    @csrf
                    @method('PUT')
                    <div class="table-wrap">
                        <table class="table">
                            <thead><tr><th>{{ __('Records') }}</th><th>{{ __('Keep for') }}</th><th class="num">{{ __('To remove now') }}</th></tr></thead>
                            <tbody>
                                @php
                                    $keepFields = ['activity' => 'keep_activity_days', 'license_checks' => 'keep_license_checks_days', 'failed_jobs' => 'keep_jobs_days', 'health' => 'keep_health_days'];
                                @endphp
                                @foreach ($oldRecords as $key => $kind)
                                    <tr>
                                        <td>{{ __($kind['label']) }}</td>
                                        <td>
                                            @if (isset($keepFields[$key]))
                                                <label class="sr-only" for="keep-{{ $key }}">{{ __('Days to keep: :records', ['records' => __($kind['label'])]) }}</label>
                                                <span style="display:inline-flex;align-items:center;gap:6px">
                                                    <input id="keep-{{ $key }}" class="input" type="number" name="{{ $keepFields[$key] }}" value="{{ old($keepFields[$key], $settings['database.'.$keepFields[$key]]) }}" min="{{ $key === 'activity' ? 30 : 7 }}" max="3650" style="width:90px;height:32px">
                                                    <span class="muted">{{ __('days') }}</span>
                                                </span>
                                            @else
                                                <span class="muted">{{ __('Removed right away') }}</span>
                                            @endif
                                        </td>
                                        <td class="num">{{ number_format($kind['count']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @foreach (array_diff_key($keepFields, $oldRecords) as $field)
                        <input type="hidden" name="{{ $field }}" value="{{ $settings['database.'.$field] }}">
                    @endforeach
                    <x-checkbox name="cleanup_nightly" :label="__('Clean up every night')" :checked="$settings['database.cleanup_nightly']" />
                    <x-checkbox name="optimize_weekly" :label="__('Optimize database tables every Sunday night, after a backup')" :checked="$settings['database.optimize_weekly']" />
                    <p class="muted" style="margin:0;font-size:.85rem">{{ __('Clients, invoices, payments, services and tickets are never removed here.') }}</p>
                    <div class="form-actions" style="justify-content:flex-start">
                        <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
                    </div>
                </form>
                <form method="POST" action="{{ route('admin.health.cleanup') }}" class="health-actions" style="padding:.9rem 1.1rem;border-top:1px solid var(--nb-line);background:var(--nb-surface-2)" data-confirm="{{ __('Remove :count old records now?', ['count' => number_format($oldTotal)]) }}">
                    @csrf
                    <button class="btn" type="submit" @disabled($oldTotal === 0)>{{ __('Clean up now') }}</button>
                </form>
            </section>
        </div>
    </div>
</x-layouts.admin>
