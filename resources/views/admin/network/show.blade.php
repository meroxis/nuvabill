@php $statuses = collect(\App\Enums\IncidentStatus::forKind($incident->kind))->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all(); @endphp
<x-layouts.admin :title="$incident->title">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.network.index') }}">{{ __('Network status') }}</a> · {{ \App\Models\NetworkIncident::kindLabel($incident->kind) }}</p>
            <h1 style="margin-top:.2rem">{{ $incident->title }} <x-status :value="$incident->status" /></h1>
        </div>
        @if (setting('status.enabled'))
            <a class="btn" href="{{ route('network.status') }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open the status page') }}</a>
        @endif
    </div>

    <div class="grid-2" style="align-items:start">
        <div style="display:grid;gap:14px">
            <form method="POST" action="{{ route('admin.network.incidents.updates.store', $incident) }}" class="card" style="display:grid;gap:1rem">
                @csrf
                <div class="card-header" style="margin:0"><h2>{{ __('Post an update') }}</h2></div>
                <x-select name="status" :label="__('Status')" :options="$statuses" :value="$incident->status->value" required />
                <x-textarea name="message" :label="__('Message for clients')" rows="4" required maxlength="5000" />
                <div><button class="btn btn-primary" type="submit">{{ __('Post update') }}</button></div>
            </form>

            <section class="card">
                <div class="card-header"><h2>{{ __('Timeline') }}</h2></div>
                <ol style="list-style:none;margin:0;padding:0;display:grid;gap:14px">
                    @foreach ($incident->updates as $update)
                        <li style="border-inline-start:3px solid var(--nb-line);padding-inline-start:12px">
                            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                                <x-status :value="$update->status" />
                                <span class="faint" style="font-size:.84rem">{{ $update->created_at->translatedFormat('d M Y H:i') }}@if ($update->admin) · {{ $update->admin->name }}@endif</span>
                            </div>
                            <p style="margin:.4rem 0 0;white-space:pre-line">{{ $update->message }}</p>
                        </li>
                    @endforeach
                </ol>
            </section>
        </div>

        <div style="display:grid;gap:14px">
            <form method="POST" action="{{ route('admin.network.incidents.update', $incident) }}" class="card" style="display:grid;gap:1rem">
                @csrf
                @method('PUT')
                <div class="card-header" style="margin:0"><h2>{{ __('Details') }}</h2></div>
                @include('admin.network.fields')
                <div><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
            </form>

            <form method="POST" action="{{ route('admin.network.incidents.destroy', $incident) }}" data-confirm="{{ __('Delete this note? Clients will no longer see it.') }}">
                @csrf
                @method('DELETE')
                <button class="btn btn-danger btn-sm" type="submit">{{ __('Delete') }}</button>
            </form>
        </div>
    </div>
</x-layouts.admin>
