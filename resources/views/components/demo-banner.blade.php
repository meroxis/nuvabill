{{-- Top strip on the public demo site, and while staff preview a theme or order form. --}}
@php $themes = app(\App\Support\Themes::class); @endphp
@if (\App\Support\Demo::isEnabled())
    <div class="demo-banner" role="note">
        <span>{{ __('This is the Nuvabill demo. Data resets every hour, no email is sent and some settings are locked.') }}</span>
        <a href="{{ \App\Support\Branding::PRODUCT_URL }}">{{ __('Get Nuvabill free') }}</a>
    </div>
@endif
@if ($themes->isPreviewing())
    <div class="demo-banner" role="note">
        <span>{{ __('You are previewing :theme and the :form order form. Only you see this.', ['theme' => $themes->all()->get($themes->active())['name'] ?? $themes->active(), 'form' => $themes->orderForms()->get($themes->activeOrderForm())['name'] ?? __('standard')]) }}</span>
        <a href="{{ route('preview.stop') }}">{{ __('Stop preview') }}</a>
    </div>
@endif
