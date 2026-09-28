<div class="page-head">
    <div>
        <h1>{{ __('Site health') }}</h1>
        <p>{{ $intro }}
            @if ($run)
                {{ trans_choice('Last checked :time, in :count second.|Last checked :time, in :count seconds.', $seconds = max(1, (int) round($run->duration_ms / 1000)), ['time' => $run->created_at->diffForHumans(), 'count' => $seconds]) }}
            @endif
        </p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        @isset($allChecks)<a class="btn" href="{{ $allChecks }}">{{ __('All checks') }}</a>@endisset
        <form method="POST" action="{{ route('admin.health.run') }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Check now') }}</button></form>
    </div>
</div>
