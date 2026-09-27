<x-layouts.admin :title="$manifest->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.marketplace.index', ['tab' => 'installed']) }}">{{ __('Marketplace') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $manifest->name }}</h1>
            <p>{{ $manifest->description }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.marketplace.settings.update', $manifest->slug) }}" class="card" style="display:grid;gap:1.1rem;max-width:860px">
        @csrf
        @method('PUT')
        <x-checkbox name="enabled" :label="__('Switch :name on', ['name' => $manifest->name])" :checked="$enabled" />
        @if ($fields)
            <div class="form-grid">
                <x-extension-fields :fields="$fields" :values="$values" />
            </div>
        @endif
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.marketplace.show', $manifest->slug) }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
