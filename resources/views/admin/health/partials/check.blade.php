@php
    /** @var \App\Health\CheckResult $check */
    $tone = $check->ignored ? '' : $check->status->tone();
    $symbol = match (true) {
        $check->ignored, $check->status === \App\Health\Status::Skipped => '–',
        $check->status === \App\Health\Status::Passed => '✓',
        default => '!',
    };
    $hasDetails = $check->ignored || $check->advice !== '' || $check->items !== [] || $check->fix || $check->link || $check->isProblem();
    $open = ($open ?? false) && $check->isProblem();
    $itemTone = fn (?string $status): string => match ($status) { 'urgent' => 'crit', 'warning' => 'warn', default => '' };
@endphp
@if ($hasDetails)
<details class="health-check" @if ($open) open @endif>
    <summary>
@else
<div class="health-check">
    <div class="health-check-head">
@endif
        <span class="health-dot" @if ($tone) data-tone="{{ $tone }}" @endif aria-hidden="true">{{ $symbol }}</span>
        <span class="grow">
            {{ $check->displayTitle() }}
            @if ($check->ignored)
                <span class="faint" style="display:block;font-size:.8rem">{{ __('Ignored: ":reason"', ['reason' => $check->ignoreReason]) }}</span>
            @elseif ($check->isProblem() && $check->summary !== '')
                <span class="muted" style="display:block;font-size:.82rem">{{ $check->displaySummary() }}</span>
            @endif
        </span>
        <span class="aside">
            @if ($check->isProblem())
                <x-pill :tone="$tone">{{ $check->status->label() }}</x-pill>
            @elseif (! $check->ignored)
                {{ $check->status === \App\Health\Status::Skipped ? __('Not checked') : $check->displaySummary() }}
            @endif
        </span>
@if ($hasDetails)
    </summary>
    <div class="health-check-body">
        @if ($check->status === \App\Health\Status::Skipped && $check->summary !== '')
            <p class="muted" style="margin:0">{{ $check->displaySummary() }}</p>
        @endif

        @if ($check->advice !== '' && ! $check->ignored)
            <p style="margin:0">{{ $check->displayAdvice() }}</p>
        @endif

        @if ($check->items !== [] && ! $check->ignored)
            <div class="health-items">
                @foreach ($check->items as $index => $item)
                    <div>
                        @if (! empty($item['status']))<span class="health-dot" data-tone="{{ $itemTone($item['status']) }}" aria-hidden="true" style="width:16px;height:16px;font-size:.6rem">!</span>@endif
                        <span @class(['mono' => ! empty($item['mono'])]) style="overflow-wrap:anywhere">{{ $item['label'] ?? '' }}</span>
                        @if (! empty($item['value']))<span class="value">{{ $item['value'] }}</span>@endif
                        @if (! empty($item['fix']) && is_array($item['fix']))
                            <form method="POST" action="{{ route('admin.health.fix') }}" @if (! empty($item['fix']['confirm'])) data-confirm="{{ __($item['fix']['confirm']) }}" @endif>
                                @csrf
                                <input type="hidden" name="check" value="{{ $check->id }}">
                                <input type="hidden" name="item" value="{{ $index }}">
                                <button type="submit" @class(['btn btn-sm', 'btn-danger' => ! empty($item['fix']['danger'])])>{{ __($item['fix']['label']) }}</button>
                            </form>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif

        <div class="health-actions">
            @if ($check->fix && ! $check->ignored)
                <form method="POST" action="{{ route('admin.health.fix') }}" @if (! empty($check->fix['confirm'])) data-confirm="{{ __($check->fix['confirm']) }}" @endif>
                    @csrf
                    <input type="hidden" name="check" value="{{ $check->id }}">
                    <button type="submit" @class(['btn btn-sm', 'btn-primary' => empty($check->fix['danger']), 'btn-danger' => ! empty($check->fix['danger'])])>{{ __($check->fix['label']) }}</button>
                </form>
            @endif

            @if ($check->link && \Illuminate\Support\Facades\Route::has($check->link['route']) && ! $check->ignored)
                <a class="btn btn-sm" href="{{ route($check->link['route'], $check->link['parameters'] ?? []) }}">{{ __($check->link['label']) }}</a>
            @endif

            @if ($check->ignored)
                <form method="POST" action="{{ route('admin.health.unignore') }}">
                    @csrf
                    <input type="hidden" name="check" value="{{ $check->id }}">
                    <button type="submit" class="btn btn-sm">{{ __('Stop ignoring') }}</button>
                </form>
            @elseif ($check->isProblem())
                <form method="POST" action="{{ route('admin.health.ignore') }}" x-data="{ asking: false }" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                    @csrf
                    <input type="hidden" name="check" value="{{ $check->id }}">
                    <button type="button" class="btn btn-sm btn-ghost" x-show="! asking" @click="asking = true; $nextTick(() => $refs.reason.focus())">{{ __('Ignore…') }}</button>
                    <template x-if="asking">
                        <span style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                            <input x-ref="reason" class="input" name="reason" required maxlength="250" style="height:32px;min-width:220px" placeholder="{{ __('Why? For example: we use this on purpose') }}" aria-label="{{ __('Why this check can be ignored') }}">
                            <button type="submit" class="btn btn-sm">{{ __('Ignore') }}</button>
                        </span>
                    </template>
                </form>
            @endif
        </div>
    </div>
</details>
@else
    </div>
</div>
@endif
