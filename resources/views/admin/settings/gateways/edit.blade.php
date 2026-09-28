<x-layouts.admin :title="$manifest->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.extensions.index', ['tab' => 'gateways']) }}">{{ __('Extensions') }}</a> · {{ __('Payment gateway') }}</p>
            <h1 style="margin-top:.2rem">{{ $manifest->name }}</h1>
            <p>{{ $manifest->description }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('admin.settings.gateways.update', $manifest->slug) }}" class="card" style="display:grid;gap:1.1rem;max-width:860px">
        @csrf
        @method('PUT')
        <x-checkbox name="enabled" :label="__('Offer :name to clients', ['name' => $manifest->name])" :checked="$enabled" />
        <div class="form-grid">
            <x-extension-fields :fields="$fields" :values="$values" />
        </div>
        @if ($manifest->slug !== 'banktransfer')
            <div class="flash" data-tone="info" style="display:grid;gap:.4rem">
                <span>{{ __('Webhook URL for :name:', ['name' => $manifest->name]) }}</span>
                <code class="mono" style="overflow-wrap:anywhere">{{ $webhookUrl }}</code>
                <span><button type="button" class="btn btn-sm" data-copy="{{ $webhookUrl }}" data-copied="{{ __('Copied') }}">{{ __('Copy URL') }}</button></span>
            </div>
        @endif
        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Save') }}</button>
            <a class="btn" href="{{ route('admin.extensions.index', ['tab' => 'gateways']) }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
