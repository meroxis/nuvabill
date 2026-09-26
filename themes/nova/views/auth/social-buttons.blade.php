{{-- "Continue with Google / GitHub / Facebook" buttons. $socialProviders: slug => provider. --}}
@if (! empty($socialProviders))
    <div class="social-buttons">
        @foreach ($socialProviders as $slug => $provider)
            <a class="btn btn-block social-btn" href="{{ route('client.social.redirect', $slug) }}">
                <x-brand-icon :name="$slug" />
                {{ __('Continue with :provider', ['provider' => $provider->name()]) }}
            </a>
        @endforeach
    </div>
    <p class="or-divider"><span>{{ __('or') }}</span></p>
@endif
