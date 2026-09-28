@php $editing = $group->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit group') : __('New group')">
    <div class="page-head"><div><h1>{{ $editing ? __('Edit group') : __('New product group') }}</h1></div></div>

    <form method="POST" action="{{ $editing ? route('admin.product-groups.update', $group) : route('admin.product-groups.store') }}" class="card" style="display:grid;gap:1.1rem;max-width:720px">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="form-grid">
            <x-input name="name" :label="__('Name')" :value="$group->name" required :help="__('For example “Web hosting”.')" />
            <x-input name="slug" :label="__('Web address')" :value="$group->slug" :help="__('Used in the store link. Leave empty to create it from the name.')" />
            <x-textarea name="description" :label="__('Description')" :value="$group->description" rows="3" class="span-2" />
            <x-input name="sort_order" type="number" min="0" :label="__('Sort order')" :value="$group->sort_order ?? 0" />
            <x-checkbox name="is_visible" :label="__('Show in the store')" :checked="$group->is_visible" />
        </div>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save group') }}</button>
            <a class="btn" href="{{ route('admin.products.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($editing)
        <form method="POST" action="{{ route('admin.product-groups.destroy', $group) }}" data-confirm="{{ __('Delete this group?') }}">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit">{{ __('Delete group') }}</button>
        </form>
    @endif
</x-layouts.admin>
