{{-- One issue or maintenance on the network status page, with its updates. --}}
@php
    $affected = $incident->servers();
@endphp
<div class="incident">
    <div class="incident-head">
        <strong><bdi>{{ $incident->title }}</bdi></strong>
        <x-status :value="$incident->status" />
    </div>
    <div class="incident-meta">
        @if ($incident->isMaintenance() && $incident->starts_at)
            {{ $incident->ends_at
                ? __(':start to :end', ['start' => $incident->starts_at->translatedFormat('d M Y H:i'), 'end' => $incident->ends_at->translatedFormat('d M Y H:i')])
                : $incident->starts_at->translatedFormat('d M Y H:i') }}
        @elseif ($incident->starts_at)
            {{ __('Since :date', ['date' => $incident->starts_at->translatedFormat('d M Y H:i')]) }}
        @endif
        @if ($incident->isOpen() && ! $incident->isMaintenance()) · {{ \App\Models\NetworkIncident::impactLabel($incident->impact) }}@endif
        @if ($affected->isNotEmpty()) · {{ $affected->map->publicName()->implode(', ') }}@endif
    </div>
    <ol class="incident-updates">
        @foreach ($incident->updates as $update)
            <li>
                <span class="incident-meta">{{ $update->status->label() }} · {{ $update->created_at->translatedFormat('d M Y H:i') }}</span>
                <p dir="auto">{{ $update->message }}</p>
            </li>
        @endforeach
    </ol>
</div>
