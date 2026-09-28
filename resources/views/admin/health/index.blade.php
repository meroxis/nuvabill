<x-layouts.admin :title="__('Site health')">
    @include('admin.health.partials.head', ['run' => $run, 'allChecks' => route('admin.health.checks', 'security'), 'intro' => __('Nuvabill looks for weak spots every night: staff access, files, settings, payments, extensions, its own code and backups.')])
    @include('admin.health.partials.tabs')

    @if ($run === null)
        <section class="card">
            <div class="empty">
                <strong>{{ __('Not checked yet') }}</strong>
                {{ __('The first check takes a few seconds. After that it runs every night.') }}
                <form method="POST" action="{{ route('admin.health.run') }}" style="margin-top:.8rem">@csrf<button class="btn btn-primary" type="submit"><x-icon name="shield" />{{ __('Check now') }}</button></form>
            </div>
        </section>
    @else
        @php
            $score = $run->security_score;
            $urgent = $run->problemCount('security', \App\Health\Status::Urgent);
            $warnings = $run->problemCount('security', \App\Health\Status::Warning);
            $verdict = match (true) {
                $score === null => __('Not enough checks ran'),
                $score >= 90 => __('Very good'),
                $score >= 75 => __('Good'),
                $score >= 50 => __('Needs work'),
                default => __('At risk'),
            };
            $ringTone = $score === null || $score >= 90 ? '--nb-good' : ($score >= 70 ? '--nb-warn' : '--nb-crit');
        @endphp

        <div class="grid-2">
            <section class="card health-summary">
                <div class="health-ring" style="--score: {{ $score ?? 0 }}; --tone: var({{ $ringTone }})" role="img" aria-label="{{ __('Security score :score of 100', ['score' => $score ?? '—']) }}">
                    <div><b>{{ $score ?? '—' }}</b><small>{{ __('of 100') }}</small></div>
                </div>
                <div style="display:grid;gap:.6rem">
                    <h2 style="margin:0;font-size:1.15rem">
                        {{ $verdict }}@if ($urgent){{ ', ' }}{{ trans_choice('with :count urgent issue|with :count urgent issues', $urgent, ['count' => $urgent]) }}@endif
                    </h2>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        @if ($urgent)<x-pill tone="crit">{{ trans_choice(':count urgent|:count urgent', $urgent, ['count' => $urgent]) }}</x-pill>@endif
                        @if ($warnings)<x-pill tone="warn">{{ trans_choice(':count should fix|:count should fix', $warnings, ['count' => $warnings]) }}</x-pill>@endif
                        <x-pill tone="good">{{ trans_choice(':count passed|:count passed', $run->problemCount('security', \App\Health\Status::Passed), ['count' => $run->problemCount('security', \App\Health\Status::Passed)]) }}</x-pill>
                    </div>
                    @if (count($history) > 1)
                        @php
                            $points = collect($history)->values()->map(fn (array $day, int $i): string => round($i / max(1, count($history) - 1) * 200, 1).','.round(40 - $day['score'] * 0.4, 1))->implode(' ');
                        @endphp
                        <div>
                            <svg viewBox="0 0 200 40" width="200" height="40" role="img" aria-label="{{ __('Score in the last 30 days: from :first to :last', ['first' => $history[0]['score'], 'last' => end($history)['score']]) }}" style="overflow:visible">
                                <polyline points="{{ $points }}" fill="none" stroke="var(--nb-accent)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />
                            </svg>
                            <div class="faint" style="font-size:.78rem">{{ __('Last 30 days') }} · {{ $history[0]['date'] }}: {{ $history[0]['score'] }} → {{ end($history)['date'] }}: {{ end($history)['score'] }}</div>
                        </div>
                    @endif
                </div>
            </section>

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Since the last check') }}</h2></div>
                @forelse ($changes['new'] as $check)
                    <div class="attention" data-tone="{{ $check->status->tone() }}"><span><b>{{ __('New') }}</b> · {{ $check->displayTitle() }}</span></div>
                @empty
                @endforelse
                @foreach ($changes['fixed'] as $check)
                    <div class="attention" data-tone="good"><span><b>{{ __('Fixed') }}</b> · {{ $check->displayTitle() }}</span></div>
                @endforeach
                @if ($changes['first'])
                    <div class="attention" data-tone="info"><span>{{ __('This is the first check. From the next one on, you see here what is new and what was fixed.') }}</span></div>
                @elseif ($changes['new']->isEmpty() && $changes['fixed']->isEmpty())
                    <div class="attention" data-tone="info"><span>{{ __('Nothing changed.') }}</span></div>
                @endif
            </section>
        </div>

        <section class="card card-flush">
            <div class="card-header">
                <h2>{{ __('Fix these first') }}</h2>
                <span class="muted" style="font-size:.85rem">{{ __('Most important at the top') }}</span>
            </div>
            @forelse ($problems as $check)
                @include('admin.health.partials.check', ['check' => $check, 'open' => $loop->first])
            @empty
                <div class="attention" data-tone="good"><span>{{ __('Nothing to fix. Well done.') }}</span></div>
            @endforelse
        </section>

        <div class="health-groups">
            @foreach ($groups as $card)
                <a class="card health-group" href="{{ route('admin.health.group', ['security', $card['group']->key()]) }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                        <h3 style="margin:0;font-size:1rem;display:flex;align-items:center;gap:8px"><x-icon :name="$card['group']->icon()" />{{ __($card['group']->title()) }}</h3>
                        @if ($card['urgent'])
                            <x-pill tone="crit">{{ trans_choice(':count urgent|:count urgent', $card['urgent'], ['count' => $card['urgent']]) }}</x-pill>
                        @elseif ($card['warning'])
                            <x-pill tone="warn">{{ trans_choice(':count to fix|:count to fix', $card['warning'], ['count' => $card['warning']]) }}</x-pill>
                        @elseif ($card['counted'] > 0)
                            <x-pill tone="good">{{ __('All passed') }}</x-pill>
                        @else
                            <x-pill>{{ __('Not checked') }}</x-pill>
                        @endif
                    </div>
                    <span class="muted" style="font-size:.85rem">{{ $card['counted'] > 0 ? __(':passed of :total passed', ['passed' => $card['passed'], 'total' => $card['counted']]) : __('Nothing could be checked here') }}@if ($card['ignored']) · {{ trans_choice(':count ignored|:count ignored', $card['ignored'], ['count' => $card['ignored']]) }}@endif</span>
                    <div class="health-bar"><span style="width:{{ $card['counted'] ? round($card['passed'] / $card['counted'] * 100) : 0 }}%"></span></div>
                </a>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.health.settings') }}" class="card" style="display:grid;gap:.9rem">
        @csrf
        @method('PUT')
        <div class="card-header" style="margin:0"><h2>{{ __('Check settings') }}</h2></div>
        <x-checkbox name="nightly" :label="__('Run the check every night and after every update')" :checked="$settings['health.nightly']" />
        <x-checkbox name="outside_check" :label="__('Open my site from outside during the check')" :help="__('Tries a few private addresses like /.env to be sure they are blocked.')" :checked="$settings['health.outside_check']" />
        <x-checkbox name="email_urgent" :label="__('Email staff who may fix security issues about new urgent issues')" :checked="$settings['health.email_urgent']" />
        <x-checkbox name="email_warnings" :label="__('Also email about new issues to fix soon')" :checked="$settings['health.email_warnings']" />
        <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
    </form>
</x-layouts.admin>
