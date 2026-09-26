<x-layouts.admin :title="__('Your profile')">
    <div class="page-head">
        <div>
            <h1>{{ __('Your profile') }}</h1>
            <p>{{ $admin->name }} · {{ $admin->email }} · {{ $admin->role?->name }}</p>
        </div>
    </div>

    <div class="grid-halves">
        <section class="card" style="display:grid;gap:1rem;align-content:start">
            <div class="card-header" style="margin:0">
                <h2>{{ __('Two-factor login') }}</h2>
                @if ($admin->hasTwoFactorEnabled())<x-pill tone="good">{{ __('On') }}</x-pill>@else<x-pill tone="warn">{{ __('Off') }}</x-pill>@endif
            </div>

            @if ($recoveryCodes)
                <div class="flash" data-tone="warn" style="display:grid;gap:.6rem">
                    <b>{{ __('Save these recovery codes now. They are shown only once.') }}</b>
                    <span>{{ __('Each code works once if you lose your phone.') }}</span>
                    <code class="mono" style="display:grid;grid-template-columns:repeat(2,max-content);gap:.3rem 1.5rem">
                        @foreach ($recoveryCodes as $code)<span>{{ $code }}</span>@endforeach
                    </code>
                    <span><button type="button" class="btn btn-sm" data-copy="{{ implode("\n", $recoveryCodes) }}" data-copied="{{ __('Copied') }}">{{ __('Copy codes') }}</button></span>
                </div>
            @endif

            @if ($admin->hasTwoFactorEnabled())
                <p class="muted" style="margin:0">{{ __('When you sign in, you enter a code from your authenticator app after your password.') }}</p>
                <form method="POST" action="{{ route('admin.profile.two-factor.disable') }}" style="display:grid;gap:.8rem" data-confirm="{{ __('Turn off two-factor login? Your account will be easier to break into.') }}">
                    @csrf
                    @method('DELETE')
                    <x-input name="current_password" type="password" :label="__('Your password')" id="disable-password" required autocomplete="current-password" />
                    <button class="btn btn-danger" type="submit">{{ __('Turn off two-factor login') }}</button>
                </form>
            @elseif ($settingUp)
                <ol style="margin:0;padding-inline-start:1.2rem;display:grid;gap:.4rem" class="muted">
                    <li>{{ __('Open an authenticator app (Google Authenticator, Authy, 1Password, Microsoft Authenticator).') }}</li>
                    <li>{{ __('Scan this QR code.') }}</li>
                    <li>{{ __('Type the 6-digit code the app shows.') }}</li>
                </ol>
                <div style="background:#fff;padding:12px;border-radius:10px;justify-self:start;line-height:0">{!! $qrCode !!}</div>
                <p class="muted" style="margin:0;font-size:.85rem">{{ __('Cannot scan? Enter this key:') }} <code class="mono" style="overflow-wrap:anywhere">{{ $admin->two_factor_secret }}</code></p>
                <form method="POST" action="{{ route('admin.profile.two-factor.confirm') }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                    @csrf
                    <x-input name="code" :label="__('Code from the app')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                    <button class="btn btn-primary" type="submit">{{ __('Turn on') }}</button>
                </form>
            @else
                <p class="muted" style="margin:0">{{ __('Protect your account with a code from your phone. Anyone with access to billing should turn this on.') }}</p>
                <form method="POST" action="{{ route('admin.profile.two-factor.start') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit"><x-icon name="shield" />{{ __('Set up two-factor login') }}</button>
                </form>
            @endif
        </section>

        <form method="POST" action="{{ route('admin.profile.password') }}" class="card" style="display:grid;gap:1rem;align-content:start">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Change password') }}</h2></div>
            <x-input name="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" />
            <x-input name="password" type="password" :label="__('New password')" :help="__('At least 10 characters.')" required autocomplete="new-password" />
            <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Change password') }}</button></div>
        </form>
    </div>
</x-layouts.admin>
