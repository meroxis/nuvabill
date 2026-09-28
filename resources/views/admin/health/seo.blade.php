<x-layouts.admin :title="__('Site health')">
    @include('admin.health.partials.head', ['run' => $run, 'allChecks' => route('admin.health.checks', 'seo'), 'intro' => __('How Google and other search engines see your store, and what to fix so more people find you.')])
    @include('admin.health.partials.tabs')

    @if ($run === null || ! $checked)
        <section class="card">
            <div class="empty">
                <strong>{{ __('Not checked yet') }}</strong>
                {{ __('The first check takes a few seconds. After that it runs every night.') }}
                <form method="POST" action="{{ route('admin.health.run') }}" style="margin-top:.8rem">@csrf<button class="btn btn-primary" type="submit"><x-icon name="search" />{{ __('Check now') }}</button></form>
            </div>
        </section>
    @else
        @php
            $score = $run->seo_score;
            $warnings = $run->problemCount('seo', \App\Health\Status::Warning) + $run->problemCount('seo', \App\Health\Status::Urgent);
            $verdict = match (true) {
                $score === null => __('Not enough checks ran'),
                $score >= 90 => __('Very good'),
                $score >= 75 => __('Good'),
                $score >= 50 => __('Fair'),
                default => __('Hard to find'),
            };
            $ringTone = $score === null || $score >= 90 ? '--nb-good' : ($score >= 70 ? '--nb-warn' : '--nb-crit');
        @endphp

        <div class="grid-2">
            <section class="card health-summary">
                <div class="health-ring" style="--score: {{ $score ?? 0 }}; --tone: var({{ $ringTone }})" role="img" aria-label="{{ __('Search engine score :score of 100', ['score' => $score ?? '—']) }}">
                    <div><b>{{ $score ?? '—' }}</b><small>{{ __('of 100') }}</small></div>
                </div>
                <div style="display:grid;gap:.6rem">
                    <h2 style="margin:0;font-size:1.15rem">{{ $verdict }}@if ($warnings){{ ': ' }}{{ trans_choice(':count thing to fix|:count things to fix', $warnings, ['count' => $warnings]) }}@endif</h2>
                    <div style="display:flex;flex-wrap:wrap;gap:6px">
                        @if ($warnings)<x-pill tone="warn">{{ trans_choice(':count should fix|:count should fix', $warnings, ['count' => $warnings]) }}</x-pill>@endif
                        <x-pill tone="good">{{ trans_choice(':count passed|:count passed', $run->problemCount('seo', \App\Health\Status::Passed), ['count' => $run->problemCount('seo', \App\Health\Status::Passed)]) }}</x-pill>
                    </div>
                    <p class="muted" style="margin:0;font-size:.85rem">
                        {{ trans_choice(':count store page is shown to search engines.|:count store pages are shown to search engines.', $shown, ['count' => $shown]) }}
                        @if ($hidden) {{ trans_choice(':count is hidden on purpose.|:count are hidden on purpose.', $hidden, ['count' => $hidden]) }}@endif
                        {{ __('The client area, cart and checkout are always hidden.') }}
                    </p>
                </div>
            </section>

            <section class="card" style="display:grid;gap:.7rem;align-content:start">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap">
                    <h2 style="margin:0;font-size:1rem">{{ __('Your home page on Google') }}</h2>
                    <a class="btn btn-sm" href="{{ route('admin.settings.seo.edit') }}">{{ __('Search engine settings') }}</a>
                </div>
                <div class="serp">
                    <span class="serp-url" dir="ltr">{{ $home['url'] }}</span>
                    <span class="serp-title">{{ $home['title'] }}</span>
                    @if ($home['description'] !== '')<span class="serp-text">{{ $home['description'] }}</span>@endif
                </div>
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
                <a class="card health-group" href="{{ route('admin.health.group', ['seo', $card['group']->key()]) }}">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
                        <h3 style="margin:0;font-size:1rem;display:flex;align-items:center;gap:8px"><x-icon :name="$card['group']->icon()" />{{ __($card['group']->title()) }}</h3>
                        @if ($card['urgent'] + $card['warning'])
                            <x-pill tone="warn">{{ trans_choice(':count to fix|:count to fix', $card['urgent'] + $card['warning'], ['count' => $card['urgent'] + $card['warning']]) }}</x-pill>
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
</x-layouts.admin>
