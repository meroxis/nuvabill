<x-layouts.admin :title="__('License')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <div style="display:grid;gap:14px;max-width:760px">
        <section class="card" style="display:grid;gap:1rem">
            <div class="card-header" style="margin:0">
                <h2>{{ __('White-label license') }}</h2>
                @if ($active)
                    <x-pill tone="good">{{ __('Active') }}</x-pill>
                @elseif ($hasKey)
                    <x-pill tone="warn">{{ __('Not active') }}</x-pill>
                @else
                    <x-pill>{{ __('Community edition') }}</x-pill>
                @endif
            </div>
            <p class="muted" style="margin:0">{{ __('Nuvabill is free. Its license asks you to keep a small "Powered by Nuvabill" credit in the client area, on invoices and in emails. A White-label license removes it, so clients only see your brand.') }}</p>

            @if ($hasKey)
                <dl class="dl">
                    <dt>{{ __('Key') }}</dt><dd class="mono">{{ $maskedKey }}</dd>
                    <dt>{{ __('Last check') }}</dt><dd>{{ isset($state['checked_at']) ? \Illuminate\Support\Carbon::parse($state['checked_at'])->diffForHumans() : '—' }}</dd>
                    @if (! empty($state['message']))
                        <dt>{{ __('Message') }}</dt><dd>{{ $state['message'] }}</dd>
                    @endif
                </dl>
                <form method="POST" action="{{ route('admin.settings.license.check') }}">
                    @csrf
                    <button class="btn" type="submit"><x-icon name="refresh" />{{ __('Check again') }}</button>
                </form>
            @endif

            <form method="POST" action="{{ route('admin.settings.license.update') }}" style="display:grid;gap:.8rem">
                @csrf
                @method('PUT')
                <x-input name="key" :label="$hasKey ? __('Change the license key') : __('License key')" placeholder="NVB-XXXX-XXXX-XXXX-XXXX" autocomplete="off" :help="$hasKey ? __('Leave empty and save to remove the key.') : __('The key from your purchase email. It works on one site; test sites like localhost are free.')" />
                <div class="form-actions" style="margin:0">
                    <button class="btn btn-primary" type="submit">{{ __('Save and check') }}</button>
                    @unless ($active)
                        <a class="btn" href="{{ $buyUrl }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Buy a White-label license') }}</a>
                    @endunless
                </div>
            </form>
        </section>
    </div>
</x-layouts.admin>
