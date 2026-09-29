{{-- Language tabs for staff-written pages: the main text, then each other language. Needs $languages, $locale, $translated and $url (a closure that takes a language code or null). --}}
@if ($languages !== [])
    <nav class="filters" aria-label="{{ __('Language') }}" style="margin-bottom:14px">
        <a class="chip" href="{{ $url(null) }}" @if ($locale === null) aria-current="true" @endif lang="{{ str_replace('_', '-', \App\Support\Locales::default()) }}">{{ \App\Support\Locales::enabled()[\App\Support\Locales::default()] ?? '' }} <span class="faint">· {{ __('Main text') }}</span></a>
        @foreach ($languages as $code => $name)
            <a class="chip" href="{{ $url($code) }}" @if ($code === $locale) aria-current="true" @endif lang="{{ str_replace('_', '-', $code) }}">{{ $name }}@unless (in_array($code, $translated, true)) <span class="faint">· {{ __('Not translated') }}</span>@endunless</a>
        @endforeach
    </nav>
@endif
