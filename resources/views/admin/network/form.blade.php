@php $statuses = collect(\App\Enums\IncidentStatus::forKind($incident->kind))->reject->isClosed()->mapWithKeys(fn ($status) => [$status->value => $status->label()])->all(); @endphp
<x-layouts.admin :title="$incident->isMaintenance() ? __('Plan maintenance') : __('Report an issue')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.network.index') }}">{{ __('Network status') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $incident->isMaintenance() ? __('Plan maintenance') : __('Report an issue') }}</h1>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.network.incidents.store') }}" class="card" style="display:grid;gap:1.1rem;max-width:860px">
        @csrf
        <input type="hidden" name="kind" value="{{ $incident->kind }}">
        @include('admin.network.fields')
        <div class="form-grid">
            <x-select name="status" :label="__('Status')" :options="$statuses" :value="array_key_first($statuses)" required />
            <x-textarea name="message" :label="__('Message for clients')" rows="5" class="span-2" required maxlength="5000"
                :help="$incident->isMaintenance() ? __('What you will do, and what clients may notice.') : __('What is wrong, and what you are doing about it. You can post updates later.')" />
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Post') }}</button>
            <a class="btn" href="{{ route('admin.network.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
