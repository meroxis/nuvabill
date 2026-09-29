{{-- Title, impact, affected servers and times of a network note. --}}
@php $chosen = array_map('intval', (array) old('server_ids', $incident->serverIds())); @endphp
<div class="form-grid">
    <x-input name="title" :label="__('Title')" :value="$incident->title" required maxlength="190" class="span-2" :help="$incident->isMaintenance() ? __('For example “Moving server 2 to faster disks”.') : __('For example “Email is slow on server 2”.')" />
    <x-select name="impact" :label="__('What clients notice')" :options="[\App\Models\NetworkIncident::IMPACT_MINOR => \App\Models\NetworkIncident::impactLabel('minor'), \App\Models\NetworkIncident::IMPACT_MAJOR => \App\Models\NetworkIncident::impactLabel('major')]" :value="$incident->impact" required />
    <x-input name="starts_at" type="datetime-local" :label="$incident->isMaintenance() ? __('Starts') : __('Started')" :value="$incident->starts_at?->format('Y-m-d\TH:i')" />
    @if ($incident->isMaintenance())
        <x-input name="ends_at" type="datetime-local" :label="__('Expected to end')" :value="$incident->ends_at?->format('Y-m-d\TH:i')" />
    @endif
    <fieldset class="field span-2" style="border:0;padding:0;margin:0">
        <legend style="font-weight:600;font-size:.9rem;margin-bottom:6px">{{ __('Servers') }}</legend>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:6px 14px">
            @foreach ($servers as $id => $name)
                <label class="check"><input type="checkbox" name="server_ids[]" value="{{ $id }}" @checked(in_array((int) $id, $chosen, true))> {{ $name }}</label>
            @endforeach
        </div>
        <p class="help" style="margin:.4rem 0 0">{{ __('Clients with services on these servers see it on their dashboard. Tick none when it touches everyone.') }}</p>
    </fieldset>
</div>
