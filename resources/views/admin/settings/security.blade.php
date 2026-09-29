<x-layouts.admin :title="__('Security')">
    <x-settings-page>

        @php
            $methods = old('client_two_factor_methods', setting('security.client_two_factor_methods'));
            $forms = old('captcha_forms', setting('security.captcha_forms'));
        @endphp

        <div style="display:grid;gap:14px;max-width:860px">
            <form method="POST" action="{{ route('admin.settings.security.update') }}" style="display:grid;gap:14px">
                @csrf
                @method('PUT')

                <section class="card" style="display:grid;gap:1rem">
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Two-factor sign-in') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('After the password, people also enter a code from their phone or email. This stops most account break-ins.') }}</p>
                    </div>
                    <div class="form-grid">
                        <x-select name="staff_two_factor" :label="__('Staff')" :value="setting('security.staff_two_factor')" :options="['optional' => __('Optional: each person chooses'), 'required' => __('Required for all staff')]"
                            :help="$staffWithoutTwoFactor > 0 ? trans_choice(':count active staff member has not set it up yet.|:count active staff members have not set it up yet.', $staffWithoutTwoFactor) : __('All active staff have it on.')" />
                        <x-select name="client_two_factor" :label="__('Clients')" :value="setting('security.client_two_factor')" :options="['off' => __('Off'), 'optional' => __('Optional: clients turn it on in Account'), 'required' => __('Required for all clients')]"
                            :help="trans_choice(':count client has it on.|:count clients have it on.', $clientsWithTwoFactor)" />
                    </div>
                    <div class="field">
                        <label>{{ __('How clients get their code') }}</label>
                        <label class="check"><input type="checkbox" name="client_two_factor_methods[]" value="totp" @checked(in_array('totp', (array) $methods, true))><span>{{ __('Authenticator app') }}<br><span class="help">{{ __('Google Authenticator, Microsoft Authenticator, Authy. Safest.') }}</span></span></label>
                        <label class="check"><input type="checkbox" name="client_two_factor_methods[]" value="email" @checked(in_array('email', (array) $methods, true))><span>{{ __('Code by email') }}<br><span class="help">{{ __('Easiest: no app needed. Make sure your email settings work.') }}</span></span></label>
                        @error('client_two_factor_methods')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <p class="faint" style="margin:0;font-size:.82rem">{{ __('Staff set up their own two-factor login on their profile page, with an authenticator app.') }}</p>
                </section>

                <section class="card" style="display:grid;gap:1rem">
                    <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap">
                        <div>
                            <h2 style="font-size:1.05rem">{{ __('CAPTCHA') }}</h2>
                            <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Stops bots from creating accounts and guessing passwords.') }}</p>
                        </div>
                        @if ($captchaState === 'on')
                            <x-pill tone="good">{{ __('On') }}</x-pill>
                        @elseif ($captchaState === 'check')
                            <x-pill tone="warn">{{ __('Waiting for check') }}</x-pill>
                        @else
                            <x-pill>{{ __('Off') }}</x-pill>
                        @endif
                    </div>
                    <div class="form-grid">
                        <x-select name="captcha_provider" :label="__('Provider')" :value="setting('security.captcha_provider')" class="span-2"
                            :options="['off' => __('Off')] + collect($captchaProviders)->map(fn ($provider) => $provider['name'])->all()"
                            :help="__('Cloudflare Turnstile is free and usually shows no puzzle.')" />
                        <x-input name="captcha_site_key" :label="__('Site key')" :value="setting('security.captcha_site_key')" autocomplete="off" spellcheck="false" />
                        <x-input name="captcha_secret" type="password" :label="__('Secret key')" :help="$hasCaptchaSecret ? __('Saved. Leave empty to keep it.') : null" autocomplete="new-password" />
                    </div>
                    <div class="field">
                        <label>{{ __('Ask on these forms') }}</label>
                        <div style="display:grid;gap:.3rem;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))">
                            @foreach ($captchaForms as $form => $label)
                                <label class="check"><input type="checkbox" name="captcha_forms[]" value="{{ $form }}" @checked(in_array($form, (array) $forms, true))><span>{{ __($label) }}</span></label>
                            @endforeach
                        </div>
                    </div>
                    <p class="faint" style="margin:0;font-size:.82rem">
                        {{ __('Get keys:') }}
                        @foreach ($captchaProviders as $provider)
                            <a href="{{ $provider['console'] }}" target="_blank" rel="noopener noreferrer">{{ $provider['name'] }}</a>@if (! $loop->last) · @endif
                        @endforeach
                    </p>
                </section>

                <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
            </form>

            @if ($captchaState === 'check')
                <form method="POST" action="{{ route('admin.settings.security.captcha-check') }}" class="card" style="display:grid;gap:1rem">
                    @csrf
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Check the CAPTCHA keys') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Solve it once here. Forms only start asking for it after this check passes, so wrong keys can never lock anyone out.') }}</p>
                    </div>
                    <x-captcha form="settings" :always="true" />
                    <div class="form-actions"><button class="btn btn-primary" type="submit"><x-icon name="shield" />{{ __('Check and turn on') }}</button></div>
                </form>
            @endif
        </div>
    </x-settings-page>
</x-layouts.admin>
