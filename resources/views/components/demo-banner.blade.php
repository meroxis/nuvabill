{{-- Top strip on the public demo site. --}}
@if (\App\Support\Demo::isEnabled())
    <div class="demo-banner" role="note">
        <span>{{ __('This is the Nuvabill demo. Data resets every hour, no email is sent and some settings are locked.') }}</span>
        <a href="{{ \App\Support\Branding::PRODUCT_URL }}">{{ __('Get Nuvabill free') }}</a>
    </div>
@endif
