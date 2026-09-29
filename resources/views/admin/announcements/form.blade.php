@php
    $editing = $announcement->exists;
    $translation = $locale ? $announcement->translationFor($locale) : null;
    $markdownHelp = __('Markdown works: ## headings, **bold**, [link text](https://...), lists with - and pictures with ![text](https://...).');
@endphp
<x-layouts.admin :title="$editing ? $announcement->title : __('New announcement')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.announcements.index') }}">{{ __('Announcements') }}</a></p>
            <h1 style="margin-top:.2rem">{{ $editing ? $announcement->title : __('New announcement') }}</h1>
        </div>
        @if ($editing && $announcement->isPublic() && setting('announcements.enabled'))
            <a class="btn" href="{{ route('announcements.show', $announcement->slug) }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open on your site') }}</a>
        @endif
    </div>

    @if ($editing)
        @include('admin.partials.content-languages', [
            'translated' => $announcement->translatedLocales(),
            'url' => fn (?string $code): string => route('admin.announcements.edit', array_filter([$announcement, 'lang' => $code])),
        ])
    @endif

    <form method="POST" action="{{ $editing ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}" class="card" style="display:grid;gap:1.1rem">
        @csrf
        @if ($editing) @method('PUT') @endif
        @if ($locale)
            <input type="hidden" name="locale" value="{{ $locale }}">
            <p class="muted" style="margin:0">{{ __('Visitors who picked :language see this version. Empty fields use the main text.', ['language' => $languages[$locale]]) }}</p>
            <div dir="{{ \App\Support\Locales::isRtl($locale) ? 'rtl' : 'ltr' }}" lang="{{ str_replace('_', '-', $locale) }}" style="display:grid;gap:1.1rem">
                <x-input name="title" :label="__('Title')" :value="$translation?->title" :placeholder="$announcement->title" maxlength="190" />
                <x-textarea name="body" :label="__('Announcement')" :value="$translation?->body" rows="14" class="mono" :placeholder="$announcement->body" :help="$markdownHelp" />
            </div>
        @else
            <div class="form-grid">
                <x-input name="title" :label="__('Title')" :value="$announcement->title" required maxlength="190" class="span-2" />
                <x-input name="published_at" type="datetime-local" :label="__('Date')" :value="$announcement->published_at?->format('Y-m-d\TH:i')" :help="__('A date in the future shows the announcement from then.')" />
                <x-input name="slug" :label="__('Web address')" :value="$announcement->slug" maxlength="190" :help="__('Leave empty to create it from the title.')" />
                <x-textarea name="body" :label="__('Announcement')" :value="$announcement->body" rows="14" class="span-2 mono" required :help="$markdownHelp" />
                <x-checkbox name="is_published" :label="__('Published')" :help="__('Off: a draft only staff can see.')" :checked="$announcement->is_published" />
            </div>
        @endif
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $locale ? __('Save translation') : __('Save announcement') }}</button>
            <a class="btn" href="{{ route('admin.announcements.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>

    @if ($editing && ! $locale)
        <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" data-confirm="{{ __('Delete this announcement?') }}" style="margin-top:14px">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit">{{ __('Delete announcement') }}</button>
        </form>
    @endif
</x-layouts.admin>
