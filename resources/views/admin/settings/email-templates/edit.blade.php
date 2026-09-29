<x-layouts.admin :title="__($template->name)">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.settings.email-templates.index') }}">{{ __('Email templates') }}</a> · <span class="mono">{{ $template->key }}</span></p>
            <h1 style="margin-top:.2rem">{{ __($template->name) }}</h1>
        </div>
    </div>

    <nav class="filters" aria-label="{{ __('Language') }}" style="margin-bottom:14px">
        @foreach ($languages as $code => $name)
            @php $done = $code === 'en' || $template->translations->contains(fn ($row) => $row->locale === $code && (filled($row->subject) || filled($row->body))); @endphp
            <a class="chip" href="{{ route('admin.settings.email-templates.edit', [$template, 'lang' => $code]) }}" @if ($code === $locale) aria-current="true" @endif lang="{{ str_replace('_', '-', $code) }}">
                {{ $name }}@unless ($done) <span class="faint">· {{ __('English text') }}</span>@endunless
            </a>
        @endforeach
    </nav>

    <div class="grid-2">
        <form method="POST" action="{{ route('admin.settings.email-templates.update', $template) }}" class="card" style="display:grid;gap:1.1rem">
            @csrf
            @method('PUT')
            <input type="hidden" name="locale" value="{{ $locale }}">
            @if ($locale === 'en')
                <x-input name="subject" :label="__('Subject')" :value="$template->subject" required />
                <x-textarea name="body" :label="__('Message')" :value="$template->body" rows="16" required :help="__('Markdown works: **bold**, [link text](https://...), and lists with -.')" class="mono" />
                <x-checkbox name="is_active" :label="__('Send this email')" :checked="$template->is_active" />
            @else
                <p class="muted" style="margin:0">{{ __('Clients who picked :language get this version. Empty fields use the English text.', ['language' => $languages[$locale]]) }}</p>
                <div dir="{{ \App\Support\Locales::isRtl($locale) ? 'rtl' : 'ltr' }}" lang="{{ str_replace('_', '-', $locale) }}" style="display:grid;gap:1.1rem">
                    <x-input name="subject" :label="__('Subject')" :value="$translation?->subject" :placeholder="$template->subject" />
                    <x-textarea name="body" :label="__('Message')" :value="$translation?->body" rows="16" class="mono" :placeholder="$template->body" :help="__('Markdown works: **bold**, [link text](https://...), and lists with -.')" />
                </div>
            @endif
            <div class="form-actions">
                <button class="btn btn-primary" type="submit">{{ __('Save template') }}</button>
                <a class="btn" href="{{ route('admin.settings.email-templates.index') }}">{{ __('Cancel') }}</a>
            </div>
        </form>

        <section class="card">
            <div class="card-header"><h2>{{ __('Placeholders') }}</h2></div>
            <p class="muted" style="margin:0 0 .8rem;font-size:.88rem">{{ __('Click to copy. Nuvabill replaces them with real values when it sends the email.') }}</p>
            <div style="display:flex;flex-wrap:wrap;gap:6px">
                @foreach ($placeholders as $placeholder)
                    @php $tag = str_repeat('{', 2).' '.$placeholder.' '.str_repeat('}', 2); @endphp
                    <button type="button" class="btn btn-sm mono" data-copy="{{ $tag }}" data-copied="{{ __('Copied') }}" dir="ltr">{{ $tag }}</button>
                @endforeach
            </div>
            <p class="muted" style="margin:.9rem 0 0;font-size:.85rem">{{ __('Emails go out in the language each client picked. Staff emails use your default language.') }}</p>
        </section>
    </div>
</x-layouts.admin>
