<x-layouts.admin :title="$manifest->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.extensions.index', ['tab' => 'registrars']) }}">{{ __('Extensions') }}</a> · {{ __('Registrar') }}</p>
            <h1 style="margin-top:.2rem">{{ $manifest->name }}</h1>
            <p>{{ $manifest->description }}</p>
        </div>
        @if ($enabled)
            <form method="POST" action="{{ route('admin.settings.registrars.test', $manifest->slug) }}">
                @csrf
                <button class="btn" type="submit"><x-icon name="refresh" />{{ __('Test connection') }}</button>
            </form>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.settings.registrars.update', $manifest->slug) }}" class="card" style="display:grid;gap:1.1rem;max-width:860px">
        @csrf
        @method('PUT')
        <x-checkbox name="enabled" :label="__('Use :name for domains', ['name' => $manifest->name])" :checked="$enabled" />
        <div class="form-grid">
            <x-extension-fields :fields="$fields" :values="$values" />
        </div>
        @if ($serverIp)
            <div class="flash" data-tone="info" style="display:grid;gap:.4rem">
                <span>{{ __('Most registrars only accept API calls from IP addresses you allow. Allow this server:') }}</span>
                <code class="mono">{{ $serverIp }}</code>
            </div>
        @endif
        <p class="muted" style="margin:0;font-size:.88rem">{{ __('Then choose this registrar for each extension under Domain prices.') }}</p>
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.extensions.index', ['tab' => 'registrars']) }}">{{ __('Back') }}</a>
        </div>
    </form>
</x-layouts.admin>
