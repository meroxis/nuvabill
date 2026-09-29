@php
    $editing = $article->exists;
    $translation = $locale ? $article->translationFor($locale) : null;
    $markdownHelp = __('Markdown works: ## headings, **bold**, [link text](https://...), lists with - and pictures with ![text](https://...).');
@endphp
<x-layouts.admin :title="$editing ? $article->title : __('New article')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.kb.index') }}">{{ __('Knowledge base') }}</a>@if ($editing) · {{ $article->category->name }}@endif</p>
            <h1 style="margin-top:.2rem">{{ $editing ? $article->title : __('New article') }}</h1>
        </div>
        @if ($editing && $article->is_published && $article->category->is_visible && setting('knowledgebase.enabled'))
            <a class="btn" href="{{ $article->url() }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Open on your site') }}</a>
        @endif
    </div>

    @if ($editing)
        @include('admin.partials.content-languages', [
            'translated' => $article->translatedLocales(),
            'url' => fn (?string $code): string => route('admin.kb.articles.edit', array_filter([$article, 'lang' => $code])),
        ])
    @endif

    <form method="POST" action="{{ $editing ? route('admin.kb.articles.update', $article) : route('admin.kb.articles.store') }}" class="card" style="display:grid;gap:1.1rem">
        @csrf
        @if ($editing) @method('PUT') @endif
        @if ($locale)
            <input type="hidden" name="locale" value="{{ $locale }}">
            <p class="muted" style="margin:0">{{ __('Visitors who picked :language see this version. Empty fields use the main text.', ['language' => $languages[$locale]]) }}</p>
            <div dir="{{ \App\Support\Locales::isRtl($locale) ? 'rtl' : 'ltr' }}" lang="{{ str_replace('_', '-', $locale) }}" style="display:grid;gap:1.1rem">
                <x-input name="title" :label="__('Title')" :value="$translation?->title" :placeholder="$article->title" maxlength="190" />
                <x-textarea name="body" :label="__('Article')" :value="$translation?->body" rows="18" class="mono" :placeholder="$article->body" :help="$markdownHelp" />
            </div>
        @else
            <div class="form-grid">
                <x-input name="title" :label="__('Title')" :value="$article->title" required maxlength="190" class="span-2" :help="__('Write it as the question clients ask, for example “How do I reset my email password?”')" />
                <x-select name="kb_category_id" :label="__('Category')" :options="$categories->all()" :value="$article->kb_category_id" required />
                <x-input name="slug" :label="__('Web address')" :value="$article->slug" maxlength="190" :help="__('Leave empty to create it from the title.')" />
                <x-textarea name="body" :label="__('Article')" :value="$article->body" rows="18" class="span-2 mono" required :help="$markdownHelp" />
                <x-input name="sort_order" type="number" min="0" :label="__('Order in category')" :value="$article->sort_order ?? 0" />
                <x-checkbox name="is_published" :label="__('Published')" :help="__('Off: a draft only staff can see.')" :checked="$article->is_published" />
            </div>
        @endif
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ $locale ? __('Save translation') : __('Save article') }}</button>
            <a class="btn" href="{{ route('admin.kb.index') }}">{{ __('Cancel') }}</a>
            @if ($editing && $article->helpful_yes + $article->helpful_no > 0)
                <span class="muted" style="margin-inline-start:auto;font-size:.88rem">{{ __('Helpful: :yes yes, :no no', ['yes' => $article->helpful_yes, 'no' => $article->helpful_no]) }}</span>
            @endif
        </div>
    </form>

    @if ($editing && ! $locale)
        <form method="POST" action="{{ route('admin.kb.articles.destroy', $article) }}" data-confirm="{{ __('Delete this article?') }}" style="margin-top:14px">
            @csrf
            @method('DELETE')
            <button class="btn btn-danger btn-sm" type="submit">{{ __('Delete article') }}</button>
        </form>
    @endif
</x-layouts.admin>
