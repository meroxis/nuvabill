@props(['action', 'locales'])
@if (count($locales) > 1)
    <form method="POST" action="{{ $action }}" {{ $attributes->merge(['class' => 'language-switcher']) }}>
        @csrf
        <x-icon name="globe" />
        <select name="locale" data-autosubmit aria-label="{{ __('Language') }}">
            @foreach ($locales as $code => $name)
                <option value="{{ $code }}" lang="{{ $code }}" @selected($code === app()->getLocale())>{{ $name }}</option>
            @endforeach
        </select>
        <noscript><button class="btn btn-sm" type="submit">{{ __('Change') }}</button></noscript>
    </form>
@endif
