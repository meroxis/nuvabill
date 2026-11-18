@props([
    'values' => [],
    'mainTitle' => '',
    'mainDescription' => '',
    'ready' => [],
])
{{--
    Search title and description in the site's other languages, under <x-search-appearance>.
    Posted as seo_translations[{code}][title] and [description]; saved by App\Seo\SeoTranslations.
    A language is "ready" (offered to search engines) once both texts are written in it.
--}}
@php
    $languages = \App\Support\ContentLanguages::others();
    $titleLimit = \App\Seo\Seo::TITLE_LIMIT;
    $descriptionLimit = \App\Seo\Seo::DESCRIPTION_LIMIT;
    $ready = array_values(array_intersect((array) $ready, array_keys($languages)));
    $hasErrors = collect($errors->keys())->contains(fn (string $key): bool => str_starts_with($key, 'seo_translations'));
@endphp
@if ($languages !== [])
    <details style="border:1px solid var(--nb-line);border-radius:6px;padding:.7rem .9rem" @if ($hasErrors) open @endif>
        <summary style="cursor:pointer">
            <span class="label">{{ __('Other languages') }}</span>
            <span class="muted" style="margin-inline-start:.4rem">{{ __(':ready of :count ready', ['ready' => count($ready), 'count' => count($languages)]) }}</span>
        </summary>
        <p class="help" style="margin:.6rem 0 0">{{ __('Empty fields use the main language. A language is offered to Google once both are written in that language.') }}</p>
        @error('seo_translations')<p class="error">{{ $message }}</p>@enderror

        @foreach (array_keys($languages) as $code)
            @php
                $lang = \App\Support\Locales::htmlLang($code);
                $dir = \App\Support\Locales::isRtl($code) ? 'rtl' : 'ltr';
                $titleKey = 'seo_translations.'.$code.'.title';
                $descriptionKey = 'seo_translations.'.$code.'.description';
                $id = 'f-seo-'.\Illuminate\Support\Str::slug($code);
                $titleValue = old($titleKey, $values[$code]['title'] ?? '');
                $titleValue = is_string($titleValue) ? $titleValue : '';
                $descriptionValue = old($descriptionKey, $values[$code]['description'] ?? '');
                $descriptionValue = is_string($descriptionValue) ? $descriptionValue : '';
            @endphp
            <div role="group" aria-labelledby="{{ $id }}-name" style="display:grid;gap:.6rem;padding-top:.9rem;margin-top:.9rem;border-top:1px solid var(--nb-line)" x-data="{
                title: @js($titleValue),
                description: @js($descriptionValue),
                meter(value, limit) { const length = value.trim().length; return { width: Math.min(100, Math.round(length / limit * 100)) + '%', state: length > limit ? 'long' : 'good', length } },
            }">
                <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                    <strong id="{{ $id }}-name">{{ \App\Support\Locales::displayName($code) }}</strong>
                    @if (in_array($code, $ready, true))
                        <span class="pill" data-tone="good">{{ __('Ready') }}</span>
                    @else
                        <span class="pill" data-tone="warn">{{ __('Not offered to search engines') }}</span>
                    @endif
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="{{ $id }}-title">{{ __('Title in search results') }}</label>
                        <input id="{{ $id }}-title" class="input" type="text" name="seo_translations[{{ $code }}][title]" maxlength="{{ \App\Seo\SeoTranslations::TITLE_LIMIT }}"
                            lang="{{ $lang }}" dir="{{ $dir }}" value="{{ $titleValue }}" x-model="title" placeholder="{{ $mainTitle }}" @error($titleKey) aria-invalid="true" @enderror>
                        <div class="length-meter" :data-state="meter(title, {{ $titleLimit }}).state">
                            <span><i :style="{ width: meter(title, {{ $titleLimit }}).width }"></i></span>
                            <span x-text="@js(__(':count of :limit letters')).replace(':count', meter(title, {{ $titleLimit }}).length).replace(':limit', {{ $titleLimit }})"></span>
                        </div>
                        @error($titleKey)<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="{{ $id }}-description">{{ __('Description in search results') }}</label>
                        <textarea id="{{ $id }}-description" class="textarea" name="seo_translations[{{ $code }}][description]" rows="2" maxlength="{{ \App\Seo\SeoTranslations::DESCRIPTION_LIMIT }}"
                            lang="{{ $lang }}" dir="{{ $dir }}" x-model="description" placeholder="{{ $mainDescription }}" @error($descriptionKey) aria-invalid="true" @enderror>{{ $descriptionValue }}</textarea>
                        <div class="length-meter" :data-state="meter(description, {{ $descriptionLimit }}).state">
                            <span><i :style="{ width: meter(description, {{ $descriptionLimit }}).width }"></i></span>
                            <span x-text="@js(__(':count of :limit letters')).replace(':count', meter(description, {{ $descriptionLimit }}).length).replace(':limit', {{ $descriptionLimit }})"></span>
                        </div>
                        @error($descriptionKey)<p class="error">{{ $message }}</p>@enderror
                    </div>
                </div>
            </div>
        @endforeach
    </details>
@endif
