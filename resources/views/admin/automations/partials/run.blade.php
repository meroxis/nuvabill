@php
    /** @var \App\Models\AutomationRun $run */
    $subject = $run->subject;
    // What a run was about, and what its steps did, is only shown to staff who may see that kind of record.
    $maySee = auth('admin')->user()?->hasPermission(\App\Automations\Registry::subjectPermission((string) $run->subject_type)) === true;
@endphp
<details class="automation-run">
    <summary>
        <x-pill :tone="$run->tone()">{{ $run->statusLabel() }}</x-pill>
        <span class="grow">
            @if ($showAutomation ?? true)<b>{{ $run->automation?->name ?? __('Deleted automation') }}</b> · @endif
            @if (! $maySee)
                {{ __('A record your role cannot see') }}
            @else
                {{ $subject ? \App\Automations\Context::describe($subject) : __('Deleted') }}
                <span class="muted" style="display:block;font-size:.82rem">{{ \Illuminate\Support\Str::limit($run->lastLog(), 160) }}</span>
            @endif
        </span>
        <time class="muted" datetime="{{ $run->updated_at->toIso8601String() }}">{{ $run->status === \App\Models\AutomationRun::WAITING && $run->resume_at ? __('Goes on :time', ['time' => $run->resume_at->diffForHumans()]) : $run->updated_at->diffForHumans() }}</time>
    </summary>
    @if ($maySee)
        <ol class="automation-log">
            @foreach ($run->log ?? [] as $entry)
                <li><span>{{ $entry['text'] }}</span><time class="faint">{{ \Illuminate\Support\Carbon::parse($entry['at'])->translatedFormat('d M H:i') }}</time></li>
            @endforeach
        </ol>
    @endif
</details>
