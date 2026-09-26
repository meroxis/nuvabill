<x-layouts.admin :title="$template->name">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Email template') }} · <span class="mono">{{ $template->key }}</span></p>
            <h1 style="margin-top:.2rem">{{ $template->name }}</h1>
        </div>
    </div>

    <div class="grid-2">
        <form method="POST" action="{{ route('admin.settings.email-templates.update', $template) }}" class="card" style="display:grid;gap:1.1rem">
            @csrf
            @method('PUT')
            <x-input name="subject" :label="__('Subject')" :value="$template->subject" required />
            <x-textarea name="body" :label="__('Message')" :value="$template->body" rows="16" required :help="__('Markdown works: **bold**, [link text](https://...), and lists with -.')" class="mono" />
            <x-checkbox name="is_active" :label="__('Send this email')" :checked="$template->is_active" />
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
                    <button type="button" class="btn btn-sm mono" data-copy="{{ $tag }}" data-copied="{{ __('Copied') }}">{{ $tag }}</button>
                @endforeach
            </div>
        </section>
    </div>
</x-layouts.admin>
