@props([
    'title' => null,
    'description' => null,
    'defaultTitle' => '',
    'defaultDescription' => '',
    'suggestTitle' => null,
    'suggestDescription' => null,
    'url' => '',
    'titleName' => 'seo_title',
    'descriptionName' => 'seo_description',
    'hiddenName' => 'seo_hidden',
    'hidden' => null,
])
{{-- Title and description for search results, with a live Google preview. Empty fields use the defaults shown. --}}
@php
    $titleLimit = \App\Seo\Seo::TITLE_LIMIT;
    $descriptionLimit = \App\Seo\Seo::DESCRIPTION_LIMIT;
@endphp
<div class="form-grid" x-data="{
    title: @js((string) old($titleName, $title)),
    description: @js((string) old($descriptionName, $description)),
    defaultTitle: @js($defaultTitle),
    defaultDescription: @js($defaultDescription),
    shown(value, fallback) { return value.trim() !== '' ? value.trim() : fallback },
    meter(value, fallback, limit) { const length = this.shown(value, fallback).length; return { width: Math.min(100, Math.round(length / limit * 100)) + '%', state: length > limit ? 'long' : 'good', length } },
}">
    <div style="display:grid;gap:1rem;align-content:start">
        <div class="field">
            <label for="f-{{ $titleName }}">{{ __('Title in search results') }}</label>
            <input id="f-{{ $titleName }}" class="input" type="text" name="{{ $titleName }}" maxlength="120" x-model="title" :placeholder="defaultTitle" @error($titleName) aria-invalid="true" @enderror>
            <div class="length-meter" :data-state="meter(title, defaultTitle, {{ $titleLimit }}).state">
                <span><i :style="{ width: meter(title, defaultTitle, {{ $titleLimit }}).width }"></i></span>
                <span x-text="@js(__(':count of :limit letters'))
                    .replace(':count', meter(title, defaultTitle, {{ $titleLimit }}).length).replace(':limit', {{ $titleLimit }})"></span>
            </div>
            <p class="help">{{ __('Empty uses the page name. Google shows about :count letters.', ['count' => $titleLimit]) }}</p>
            @error($titleName)<p class="error">{{ $message }}</p>@enderror
        </div>
        <div class="field">
            <label for="f-{{ $descriptionName }}">{{ __('Description in search results') }}</label>
            <textarea id="f-{{ $descriptionName }}" class="textarea" name="{{ $descriptionName }}" rows="3" maxlength="320" x-model="description" :placeholder="defaultDescription" @error($descriptionName) aria-invalid="true" @enderror></textarea>
            <div class="length-meter" :data-state="meter(description, defaultDescription, {{ $descriptionLimit }}).state">
                <span><i :style="{ width: meter(description, defaultDescription, {{ $descriptionLimit }}).width }"></i></span>
                <span x-text="@js(__(':count of :limit letters'))
                    .replace(':count', meter(description, defaultDescription, {{ $descriptionLimit }}).length).replace(':limit', {{ $descriptionLimit }})"></span>
            </div>
            <p class="help">{{ __('Empty uses the text shown in grey, made from the page’s own details.') }}</p>
            @error($descriptionName)<p class="error">{{ $message }}</p>@enderror
        </div>
        @if ($suggestTitle !== null || $suggestDescription !== null)
            <div>
                <button type="button" class="btn btn-sm" @click="title = @js((string) $suggestTitle); description = @js((string) $suggestDescription)">{{ __('Suggest from the details') }}</button>
            </div>
        @endif
        @if ($hidden !== null)
            <x-checkbox :name="$hiddenName" :label="__('Hide this page from search engines')" :checked="$hidden" :help="__('It stays in your store; Google and Bing are asked not to show it.')" />
        @endif
    </div>
    <div style="display:grid;gap:.5rem;align-content:start">
        <span class="label">{{ __('On Google') }}</span>
        <div class="serp" aria-live="polite">
            <span class="serp-url" dir="ltr">{{ $url }}</span>
            <span class="serp-title" x-text="shown(title, defaultTitle)"></span>
            <span class="serp-text" x-text="shown(description, defaultDescription)"></span>
        </div>
        <p class="faint" style="margin:0;font-size:.8rem">{{ __('Search engines decide what they show in the end; this is what Nuvabill tells them.') }}</p>
    </div>
</div>
