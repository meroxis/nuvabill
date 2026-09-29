@php
    $editing = $category->exists;
    $translation = $locale ? $category->translationFor($locale) : null;
@endphp
<x-layouts.admin :title="$editing ? __('Edit category') : __('New category')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.kb.index') }}">{{ __('Knowledge base') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $editing ? $category->name : __('New category') }}</h1>
        </div>
    </div>

    @if ($editing)
        @include('admin.partials.content-languages', [
            'translated' => $category->translatedLocales(),
            'url' => fn (?string $code): string => route('admin.kb.categories.edit', array_filter([$category, 'lang' => $code])),
        ])
    @endif

    <form method="POST" action="{{ $editing ? route('admin.kb.categories.update', $category) : route('admin.kb.categories.store') }}" class="card" style="display:grid;gap:1.1rem;max-width:760px">
        @csrf
        @if ($editing) @method('PUT') @endif
        @if ($locale)
            <input type="hidden" name="locale" value="{{ $locale }}">
            <p class="muted" style="margin:0">{{ __('Visitors who picked :language see this version. Empty fields use the main text.', ['language' => $languages[$locale]]) }}</p>
            <div dir="{{ \App\Support\Locales::isRtl($locale) ? 'rtl' : 'ltr' }}" lang="{{ str_replace('_', '-', $locale) }}" style="display:grid;gap:1.1rem">
                <x-input name="title" :label="__('Name')" :value="$translation?->title" :placeholder="$category->name" maxlength="120" />
                <x-textarea name="body" :label="__('Description')" :value="$translation?->body" rows="3" :placeholder="$category->description" maxlength="500" />
            </div>
        @else
            <div class="form-grid">
                <x-input name="name" :label="__('Name')" :value="$category->name" required maxlength="120" :help="__('For example “Email” or “Billing”.')" />
                <x-input name="slug" :label="__('Web address')" :value="$category->slug" maxlength="120" :help="__('Leave empty to create it from the name.')" />
                <x-textarea name="description" :label="__('Description')" :value="$category->description" rows="3" class="span-2" maxlength="500" :help="__('One short line under the name.')" />
                <x-input name="sort_order" type="number" min="0" :label="__('Position in the list')" :value="$category->sort_order ?? 0" />
                <x-checkbox name="is_visible" :label="__('Show on your site')" :checked="$category->is_visible" />
            </div>
        @endif
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $locale ? __('Save translation') : ($editing ? __('Save category') : __('Add category')) }}</button>
            <a class="btn" href="{{ route('admin.kb.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($editing && ! $locale)
        <form method="POST" action="{{ route('admin.kb.categories.destroy', $category) }}" data-confirm="{{ __('Delete this category?') }}" style="margin-top:14px">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit">{{ __('Delete category') }}</button>
        </form>
    @endif
</x-layouts.admin>
