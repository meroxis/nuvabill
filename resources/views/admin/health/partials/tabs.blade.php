<nav class="market-tabs health-tabs" aria-label="{{ __('Site health sections') }}">
    @foreach ($tabs as $tab)
        <a href="{{ route($tab['route']) }}" @if (request()->routeIs($tab['route'])) aria-current="page" @endif>
            {{ $tab['label'] }}
            @if ($tab['score'] !== null)<span class="score">{{ $tab['score'] }}</span>@endif
            @if ($tab['urgent'])
                <x-pill tone="crit">{{ trans_choice(':count urgent|:count urgent', $tab['urgent'], ['count' => $tab['urgent']]) }}</x-pill>
            @elseif ($tab['warning'])
                <x-pill tone="warn">{{ trans_choice(':count to fix|:count to fix', $tab['warning'], ['count' => $tab['warning']]) }}</x-pill>
            @endif
        </a>
    @endforeach
</nav>
