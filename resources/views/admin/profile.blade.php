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

    <section class="card" id="passkeys" style="display:grid;gap:1rem;margin-top:14px">
        <div class="card-header" style="margin:0">
            <h2>{{ __('Passkeys') }}</h2>
            @if ($passkeys->isNotEmpty())<x-pill tone="good">{{ trans_choice(':count passkey|:count passkeys', $passkeys->count()) }}</x-pill>@endif
        </div>
        <p class="muted" style="margin:0">{{ __('Sign in with your fingerprint, face or phone instead of your password and code. Passkeys cannot be stolen by fake sign-in pages.') }}</p>

        @if ($passkeys->isNotEmpty())
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Added') }}</th><th>{{ __('Last used') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($passkeys as $passkey)
                    <tr>
                        <td>{{ $passkey->name }}</td>
                        <td>{{ $passkey->created_at->toFormattedDateString() }}</td>
                        <td>{{ $passkey->last_used_at?->diffForHumans() ?? __('Never') }}</td>
                        <td class="end">
                            <form method="POST" action="{{ route('admin.profile.passkeys.destroy', $passkey) }}" data-confirm="{{ __('Remove this passkey? You cannot sign in with it any more.') }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove :name', ['name' => $passkey->name]) }}"><x-icon name="trash" /></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif

        <form method="POST" action="{{ route('admin.profile.passkeys.store') }}" data-passkey="register" data-options="{{ route('admin.profile.passkeys.options') }}" data-failed="{{ __('The passkey was not added. Try again.') }}" data-duplicate="{{ __('This device already has a passkey for your account.') }}" hidden>
            @csrf
            <input type="hidden" name="credential">
            <div style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
                <x-input name="name" id="passkey-name" :label="__('Passkey name')" maxlength="100" :placeholder="__('For example My laptop')" />
                <x-input name="current_password" id="passkey-password" type="password" :label="__('Your password')" required autocomplete="current-password" />
                <button class="btn btn-primary" type="submit"><x-icon name="key" />{{ __('Add a passkey') }}</button>
            </div>
            <p data-passkey-error role="alert" hidden style="margin:.5rem 0 0;color:var(--nb-crit);font-size:.85rem;font-weight:600"></p>
        </form>
        <p class="muted" data-passkey-unsupported hidden style="margin:0">{{ __('This browser cannot use passkeys. Try an up-to-date Chrome, Edge, Safari or Firefox.') }}</p>
    </section>

    <section class="card" style="display:grid;gap:1rem;margin-top:14px">
        <div class="card-header" style="margin:0">
            <h2>{{ __('API keys') }}</h2>
            <a class="row-link" href="https://nuvabill.com/docs/api/" target="_blank" rel="noopener" style="font-size:.85rem">{{ __('API guide') }}</a>
        </div>
        <p class="muted" style="margin:0">{{ __('Connect your own tools to Nuvabill with the REST API at :url. A key can do what your role allows, and nothing more.', ['url' => url('/api/v1')]) }}</p>

        @if ($newApiKey)
            <div class="flash" data-tone="good" style="display:grid;gap:.4rem">
                <b>{{ __('Your new key. Copy it now: it is shown only once.') }}</b>
                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                    <code class="mono" style="overflow-wrap:anywhere;flex:1">{{ $newApiKey }}</code>
                    <button type="button" class="btn btn-sm" data-copy="{{ $newApiKey }}" data-copied="{{ __('Copied') }}">{{ __('Copy key') }}</button>
                </div>
            </div>
        @endif

        @if ($apiTokens->isNotEmpty())
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Access') }}</th><th>{{ __('Key') }}</th><th>{{ __('Last used') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($apiTokens as $token)
                    <tr>
                        <td>{{ $token->name }}</td>
                        <td>{{ $token->can_write ? __('Read and write') : __('Read only') }}</td>
                        <td class="mono">nb_…{{ $token->hint }}</td>
                        <td>{{ $token->last_used_at?->diffForHumans() ?? __('Never') }}</td>
                        <td class="end">
                            <form method="POST" action="{{ route('admin.profile.api-keys.destroy', $token) }}" data-confirm="{{ __('Delete this key? Anything using it stops working.') }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Delete :name', ['name' => $token->name]) }}"><x-icon name="trash" /></button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif

        <form method="POST" action="{{ route('admin.profile.api-keys.store') }}" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
            @csrf
            <x-input name="name" :label="__('New key name')" required :placeholder="__('For example Accounting sync')" style="min-width:240px" />
            <x-checkbox name="can_write" :label="__('Can change data')" :help="__('Off: the key can only read.')" />
            <button class="btn btn-primary" type="submit"><x-icon name="key" />{{ __('Create key') }}</button>
        </form>
    </section>
</x-layouts.admin>
