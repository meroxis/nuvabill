<x-layouts.admin :title="__('All checks')">
    <div class="page-head">
        <div>
            <p style="margin:0 0 .2rem;font-size:.85rem">
                <a class="row-link" href="{{ route($section === 'database' ? 'admin.health.database' : 'admin.health.index') }}">{{ __('Site health') }}</a>
                <span class="muted"> / {{ $section === 'database' ? __('Database') : __('Security') }}</span>
            </p>
            <h1>{{ trans_choice('All :count check|All :count checks', $counts['all'], ['count' => $counts['all']]) }}</h1>
        </div>
        <nav class="filters" aria-label="{{ __('Show') }}">
            @foreach (['all' => __('All'), 'urgent' => __('Urgent'), 'warning' => __('Should fix'), 'passed' => __('Passed'), 'ignored' => __('Ignored')] as $key => $label)
                <a class="chip" href="{{ route('admin.health.checks', ['section' => $section, 'show' => $key]) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }} <b>{{ $counts[$key] }}</b></a>
            @endforeach
        </nav>
    </div>

    <div class="grid-halves">
        @forelse ($groups as $item)
            <section class="card card-flush" style="align-self:start">
                <div class="card-header">
                    <h2><a class="row-link" href="{{ route('admin.health.group', [$section, $item['group']->key()]) }}">{{ __($item['group']->title()) }}</a></h2>
                </div>
                @foreach ($item['checks'] as $check)
                    @include('admin.health.partials.check', ['check' => $check])
                @endforeach
            </section>
        @empty
            <section class="card"><div class="empty"><strong>{{ __('Nothing here') }}</strong>{{ __('No check matches this filter.') }}</div></section>
        @endforelse
    </div>
</x-layouts.admin>
