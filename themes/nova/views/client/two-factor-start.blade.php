@extends('theme::layouts.app')

@section('title', __('Two-factor sign-in'))

{{-- Turning on two-factor sign-in needs the password or an emailed code. The Account page has that
     field in its own forms; a theme whose forms do not send it is brought to this page. --}}
@section('content')
    <div class="auth-wrap">
        <div class="auth-box">
            <h1 style="font-size:1.6rem">{{ __('Two-factor sign-in') }}</h1>
            <p class="muted" style="margin:0">{{ __('First confirm that this is your account. Then you can turn on two-factor sign-in.') }}</p>
            <div class="card" style="display:grid;gap:1rem">
                <form method="POST" action="{{ route($method === 'email' ? 'client.account.two-factor.email' : 'client.account.two-factor.app') }}" style="display:grid;gap:1rem">
                    @csrf
                    @if ($client->has_password)
                        <x-input name="two_factor_current_password" type="password" id="two-factor-start-password" :label="__('Your password')" required autocomplete="current-password" autofocus />
                    @else
                        <x-input name="two_factor_email_code" id="two-factor-start-code" :label="__('Code from the email')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" required />
                        <span><button class="btn btn-sm" type="submit" form="account-email-code">{{ __('Email me a code') }}</button></span>
                    @endif
                    <button class="btn btn-primary btn-block" type="submit">{{ $method === 'email' ? __('Get codes by email') : __('Use an authenticator app') }}</button>
                </form>
                <form method="POST" action="{{ route('client.account.email-code') }}" id="account-email-code" hidden>@csrf</form>
            </div>
            <p class="muted" style="text-align:center;margin:0"><a href="{{ route('client.account.edit') }}#two-factor">{{ __('Cancel') }}</a></p>
        </div>
    </div>
@endsection
