@extends('theme::layouts.app')

@section('title', __('Account'))

@section('content')
    <div class="page-title"><div><h1>{{ __('Your details') }}</h1><p>{{ __('These appear on your invoices.') }}</p></div></div>

    <div class="two-col">
        <form method="POST" action="{{ route('client.account.update') }}" class="card" style="display:grid;gap:1.1rem">
            @csrf
            @method('PUT')
            <div class="form-grid">
                <x-input name="first_name" :label="__('First name')" :value="$client->first_name" required autocomplete="given-name" />
                <x-input name="last_name" :label="__('Last name')" :value="$client->last_name" required autocomplete="family-name" />
                <x-input name="email" type="email" :label="__('Email')" :value="$client->email" required autocomplete="email" />
                <x-input name="phone" type="tel" :label="__('Phone')" :value="$client->phone" autocomplete="tel" />
                <x-input name="company_name" :label="__('Company')" :value="$client->company_name" class="span-2" autocomplete="organization" />
                <x-input name="address_1" :label="__('Address')" :value="$client->address_1" autocomplete="address-line1" />
                <x-input name="address_2" :label="__('Address line 2')" :value="$client->address_2" autocomplete="address-line2" />
                <x-input name="city" :label="__('City')" :value="$client->city" autocomplete="address-level2" />
                <x-input name="state" :label="__('State or region')" :value="$client->state" autocomplete="address-level1" />
                <x-input name="postcode" :label="__('Postcode')" :value="$client->postcode" autocomplete="postal-code" />
                <x-select name="country" :label="__('Country')" :options="$countries" :value="$client->country" required />
                @if (setting('tax.enabled') || $client->tax_id)
                    <x-input name="tax_id" :label="setting('tax.id_label')" :value="$client->tax_id" :help="__('Shown on your invoices. Optional.')" />
                @endif
                {{-- Changing the sign-in email needs proof that this is the account owner. --}}
                @if ($client->has_password)
                    <x-input name="current_password" id="details-password" type="password" :label="__('Current password')" :help="__('Needed only when you change your email.')" class="span-2" autocomplete="current-password" />
                @else
                    <div class="span-2" style="display:grid;gap:.5rem">
                        <x-input name="email_code" id="details-email-code" :label="__('Code from the email')" :help="__('Needed only when you change your email.')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" />
                        <span><button class="btn btn-sm" type="submit" form="account-email-code">{{ __('Email me a code') }}</button></span>
                    </div>
                @endif
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save details') }}</button></div>
        </form>

        <div style="display:grid;gap:18px;align-content:start">
            <form method="POST" action="{{ route('client.account.password') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                @method('PUT')
                @if ($client->has_password)
                    <h2 style="font-size:1.05rem">{{ __('Change password') }}</h2>
                    <x-input name="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" />
                @else
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Set a password') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('You signed up with a connected account. Set a password to also sign in with your email.') }}</p>
                    </div>
                @endif
                <x-input name="password" type="password" :label="__('New password')" :help="__('At least 8 characters.')" required autocomplete="new-password" />
                <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
                @unless ($client->has_password)
                    <x-input name="email_code" id="password-email-code" :label="__('Code from the email')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                    <span><button class="btn btn-sm" type="submit" form="account-email-code">{{ __('Email me a code') }}</button></span>
                @endunless
                <div class="form-actions"><button class="btn btn-primary" type="submit">{{ $client->has_password ? __('Change password') : __('Set password') }}</button></div>
            </form>

            @if ($twoFactor['mode'] !== 'off' || $client->hasTwoFactorEnabled())
                <section class="card" id="two-factor" style="display:grid;gap:.9rem;scroll-margin-top:90px">
                    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center">
                        <h2 style="font-size:1.05rem">{{ __('Two-factor sign-in') }}</h2>
                        @if ($client->hasTwoFactorEnabled())<x-pill tone="good">{{ __('On') }}</x-pill>@else<x-pill :tone="$twoFactor['mode'] === 'required' ? 'crit' : 'warn'">{{ __('Off') }}</x-pill>@endif
                    </div>

                    @if ($twoFactor['recoveryCodes'])
                        <div class="flash" data-tone="warn" style="display:grid;gap:.6rem">
                            <b>{{ __('Save these recovery codes now. They are shown only once.') }}</b>
                            <span>{{ __('Each code works once if you lose your phone.') }}</span>
                            <code class="mono" style="display:grid;grid-template-columns:repeat(2,max-content);gap:.3rem 1.5rem">
                                @foreach ($twoFactor['recoveryCodes'] as $code)<span>{{ $code }}</span>@endforeach
                            </code>
                            <span><button type="button" class="btn btn-sm" data-copy="{{ implode("\n", $twoFactor['recoveryCodes']) }}">{{ __('Copy codes') }}</button></span>
                        </div>
                    @endif

                    @if ($client->hasTwoFactorEnabled())
                        <p class="muted" style="margin:0">
                            {{ $client->two_factor_method === \App\Models\Client::TWO_FACTOR_EMAIL
                                ? __('When you sign in, we email you a code to enter after your password.')
                                : __('When you sign in, you enter a code from your authenticator app after your password.') }}
                        </p>
                        @if ($twoFactor['mode'] !== 'required')
                            <form method="POST" action="{{ route('client.account.two-factor.destroy') }}" style="display:grid;gap:.8rem" data-confirm="{{ __('Turn off two-factor sign-in? Your account will be easier to break into.') }}">
                                @csrf
                                @method('DELETE')
                                @if ($client->has_password)
                                    <x-input name="current_password" type="password" id="two-factor-password" :label="__('Your password')" required autocomplete="current-password" />
                                @else
                                    <x-input name="email_code" id="two-factor-off-code" :label="__('Code from the email')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                                    <span><button class="btn btn-sm" type="submit" form="account-email-code">{{ __('Email me a code') }}</button></span>
                                @endif
                                <div><button class="btn btn-sm btn-danger" type="submit">{{ __('Turn off') }}</button></div>
                            </form>
                        @endif
                    @elseif ($twoFactor['settingUpApp'])
                        <ol class="muted" style="margin:0;padding-inline-start:1.2rem;display:grid;gap:.3rem">
                            <li>{{ __('Open an authenticator app (Google Authenticator, Microsoft Authenticator, Authy).') }}</li>
                            <li>{{ __('Scan this QR code.') }}</li>
                            <li>{{ __('Type the 6-digit code the app shows.') }}</li>
                        </ol>
                        <div style="background:#fff;padding:12px;border-radius:10px;justify-self:start;line-height:0">{!! $twoFactor['qrCode'] !!}</div>
                        <p class="muted" style="margin:0;font-size:.85rem">{{ __('Cannot scan? Enter this key:') }} <code class="mono" style="overflow-wrap:anywhere">{{ $client->two_factor_secret }}</code></p>
                        <form method="POST" action="{{ route('client.account.two-factor.app.confirm') }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                            @csrf
                            <x-input name="code" id="two-factor-app-code" :label="__('Code from the app')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                            <button class="btn btn-primary" type="submit">{{ __('Turn on') }}</button>
                        </form>
                        <form method="POST" action="{{ route('client.account.two-factor.destroy') }}">@csrf @method('DELETE')<button class="btn btn-sm" type="submit">{{ __('Cancel') }}</button></form>
                    @elseif ($twoFactor['emailPending'])
                        <form method="POST" action="{{ route('client.account.two-factor.email.confirm') }}" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
                            @csrf
                            <x-input name="code" id="two-factor-email-code" :label="__('Code from the email')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                            <button class="btn btn-primary" type="submit">{{ __('Turn on') }}</button>
                        </form>
                        <form method="POST" action="{{ route('client.account.two-factor.email') }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Send a new code') }}</button></form>
                    @else
                        <p class="muted" style="margin:0">{{ __('Protect your account: after your password, you also enter a short code. Choose how you want to get it.') }}</p>
                        <div style="display:flex;gap:8px;flex-wrap:wrap">
                            @if (in_array(\App\Models\Client::TWO_FACTOR_APP, $twoFactor['methods'], true))
                                <form method="POST" action="{{ route('client.account.two-factor.app') }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="shield" />{{ __('Use an authenticator app') }}</button></form>
                            @endif
                            @if (in_array(\App\Models\Client::TWO_FACTOR_EMAIL, $twoFactor['methods'], true))
                                <form method="POST" action="{{ route('client.account.two-factor.email') }}">@csrf<button class="btn" type="submit"><x-icon name="mail" />{{ __('Get codes by email') }}</button></form>
                            @endif
                        </div>
                    @endif
                </section>
            @endif

            @if (setting('billing.autopay'))
                <section class="card" style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                    <div style="flex:1;min-width:200px">
                        <h2 style="font-size:1.05rem">{{ __('Payment methods') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Save a card or PayPal to pay your renewals automatically.') }}</p>
                    </div>
                    <a class="btn" href="{{ route('client.account.payment-methods') }}"><x-icon name="card" />{{ __('Payment methods') }}</a>
                </section>
            @endif

            <section class="card" id="passkeys" style="display:grid;gap:.9rem;scroll-margin-top:90px">
                <div style="display:flex;justify-content:space-between;gap:10px;align-items:center">
                    <h2 style="font-size:1.05rem">{{ __('Passkeys') }}</h2>
                    @if ($passkeys->isNotEmpty())<x-pill tone="good">{{ trans_choice(':count passkey|:count passkeys', $passkeys->count()) }}</x-pill>@endif
                </div>
                <p class="muted" style="margin:0;font-size:.88rem">{{ __('Sign in with your fingerprint, face or phone. No password to type, and fake sign-in pages cannot steal it.') }}</p>

                @foreach ($passkeys as $passkey)
                    <div class="summary-row" style="align-items:center">
                        <span style="min-width:0;overflow-wrap:anywhere">
                            {{ $passkey->name }}
                            <span class="muted" style="font-size:.85rem"> · {{ $passkey->last_used_at ? __('used :time', ['time' => $passkey->last_used_at->diffForHumans()]) : __('added :date', ['date' => $passkey->created_at->toFormattedDateString()]) }}</span>
                        </span>
                        <form method="POST" action="{{ route('client.account.passkeys.destroy', $passkey) }}" data-confirm="{{ __('Remove this passkey? You cannot sign in with it any more.') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-sm" type="submit">{{ __('Remove') }}</button>
                        </form>
                    </div>
                @endforeach

                <form method="POST" action="{{ route('client.account.passkeys.store') }}" data-passkey="register" data-options="{{ route('client.account.passkeys.options') }}" data-failed="{{ __('The passkey was not added. Try again.') }}" data-duplicate="{{ __('This device already has a passkey for your account.') }}" hidden>
                    @csrf
                    <input type="hidden" name="credential">
                    <div style="display:grid;gap:.8rem">
                        <x-input name="name" id="passkey-name" :label="__('Passkey name')" maxlength="100" :placeholder="__('For example My phone')" />
                        @if ($client->has_password)
                            <x-input name="current_password" id="passkey-password" type="password" :label="__('Your password')" required autocomplete="current-password" />
                        @endif
                        <span><button class="btn btn-primary" type="submit"><x-icon name="key" />{{ __('Add a passkey') }}</button></span>
                    </div>
                    <p data-passkey-error role="alert" hidden style="margin:.5rem 0 0;color:var(--nb-crit);font-size:.85rem;font-weight:600"></p>
                </form>
                <p class="muted" data-passkey-unsupported hidden style="margin:0;font-size:.88rem">{{ __('This browser cannot use passkeys. Try an up-to-date Chrome, Edge, Safari or Firefox.') }}</p>
            </section>

            @if ($chat)
                <section class="card" id="chat-apps" style="display:grid;gap:.9rem;scroll-margin-top:90px">
                    <style>.chat-qr svg { display: block; width: 100%; height: 100%; }</style>
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Get alerts on your phone') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Get invoices, reminders and ticket replies in your chat app, and write to support from there. Scan the QR code with your phone, or tap the link on this phone.') }}</p>
                    </div>
                    @if ($chat['apps'] !== [])
                        <div style="display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(180px,1fr))">
                            @foreach ($chat['apps'] as $app)
                                <div style="display:grid;gap:.5rem;justify-items:center;text-align:center">
                                    <div class="chat-qr" style="width:150px;height:150px;padding:8px;background:#fff;border-radius:6px;box-sizing:border-box" role="img" aria-label="{{ __('QR code for :app', ['app' => $app['name']]) }}">{!! $app['qr'] !!}</div>
                                    <a class="btn btn-sm" href="{{ $app['url'] }}" target="_blank" rel="noopener">{{ __('Open :app', ['app' => $app['name']]) }}</a>
                                </div>
                            @endforeach
                        </div>
                        <p class="muted" style="margin:0;font-size:.82rem">{{ __('The code is for your account only and works for 30 minutes. Send /stop in the chat to disconnect.') }}</p>
                    @endif
                    @foreach ($chat['links'] as $link)
                        <div class="summary-row" style="align-items:center">
                            <span style="min-width:0;overflow-wrap:anywhere">{{ $link->channelLabel() }}@if ($link->name)<span class="muted" style="font-size:.85rem"> · {{ $link->name }}</span>@endif <span class="muted" style="font-size:.85rem"> · {{ __('connected :date', ['date' => $link->created_at->translatedFormat('d M Y')]) }}</span></span>
                            <form method="POST" action="{{ route('client.account.chat.destroy', $link) }}" data-confirm="{{ __('Disconnect :app? You will not get messages there anymore.', ['app' => $link->channelLabel()]) }}">
                                @csrf
                                @method('DELETE')
                                <button class="btn btn-sm" type="submit">{{ __('Disconnect') }}</button>
                            </form>
                        </div>
                    @endforeach
                </section>
            @endif

            @if ($socialProviders || $socialAccounts->isNotEmpty())
                <section class="card" style="display:grid;gap:.8rem">
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Connected accounts') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Sign in with one click using these accounts.') }}</p>
                    </div>
                    @foreach (array_keys(\App\Auth\Social\SocialLogin::PROVIDERS) as $slug)
                        @php $account = $socialAccounts->get($slug); $provider = $socialProviders[$slug] ?? null; @endphp
                        @continue(! $account && ! $provider)
                        <div class="summary-row" style="align-items:center">
                            <span style="display:inline-flex;gap:.5rem;align-items:center;min-width:0">
                                <x-brand-icon :name="$slug" style="width:18px;height:18px;flex:none" />
                                <span style="min-width:0;overflow-wrap:anywhere">
                                    {{ $provider?->name() ?? ucfirst($slug) }}
                                    @if ($account?->email)<span class="muted" style="font-size:.85rem"> · {{ $account->email }}</span>@endif
                                </span>
                            </span>
                            @if ($account)
                                <form method="POST" action="{{ route('client.account.social.destroy', $slug) }}" data-confirm="{{ __('Disconnect :provider? You can still sign in with your email and password.', ['provider' => $provider?->name() ?? ucfirst($slug)]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm" type="submit">{{ __('Disconnect') }}</button>
                                </form>
                            @else
                                <a class="btn btn-sm" href="{{ route('client.social.redirect', $slug) }}">{{ __('Connect') }}</a>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="card" id="your-data" style="display:grid;gap:.8rem;scroll-margin-top:90px">
                <div>
                    <h2 style="font-size:1.05rem">{{ __('Your data') }}</h2>
                    <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Download everything we keep about you, or ask us to erase it. Invoices stay, because the law requires them.') }}</p>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                    <a class="btn btn-sm" href="{{ route('client.account.data') }}"><x-icon name="download" />{{ __('Download my data') }}</a>
                    <form method="POST" action="{{ route('client.account.erase-request') }}" data-confirm="{{ __('Ask us to erase your data? We open a ticket and answer there. Your services must be ended first.') }}">
                        @csrf
                        <button class="btn btn-sm" type="submit">{{ __('Ask to erase my data') }}</button>
                    </form>
                </div>
            </section>
        </div>
    </div>

    @unless ($client->has_password)
        {{-- "Email me a code" buttons send this form: clients without a password confirm account changes with that code. --}}
        <form method="POST" action="{{ route('client.account.email-code') }}" id="account-email-code" hidden>@csrf</form>
    @endunless
@endsection
