@extends('theme::layouts.app')

@section('title', __('Two-factor check'))

@section('content')
    <div class="auth-wrap">
        <div class="auth-box">
            <h1 style="font-size:1.6rem">{{ __('Enter your code') }}</h1>
            <p class="muted" style="margin:0">
                @if ($method === \App\Models\Client::TWO_FACTOR_EMAIL)
                    {{ __('We sent a 6-digit code to :email. It works for 10 minutes.', ['email' => $email]) }}
                @else
                    {{ __('Open your authenticator app and enter the 6-digit code for :name.', ['name' => setting('company.name')]) }}
                @endif
            </p>
            <div class="card" x-data="{ recovery: false }" style="display:grid;gap:1rem">
                <form method="POST" action="{{ route('client.two-factor.challenge') }}" style="display:grid;gap:1rem">
                    @csrf
                    <template x-if="! recovery">
                        <x-input name="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus />
                    </template>
                    <template x-if="recovery">
                        <x-input name="recovery_code" :label="__('Recovery code')" :help="__('One of the codes you saved when you turned on two-factor sign-in.')" autocomplete="off" />
                    </template>
                    <button class="btn btn-primary btn-block" type="submit">{{ __('Continue') }}</button>
                </form>
                @if ($method === \App\Models\Client::TWO_FACTOR_EMAIL)
                    <form method="POST" action="{{ route('client.two-factor.resend') }}">
                        @csrf
                        <button class="btn btn-sm btn-block" type="submit">{{ __('Send a new code') }}</button>
                    </form>
                @else
                    <button type="button" class="btn btn-sm" @click="recovery = ! recovery"
                        x-text="recovery ? @js(__('Use a code from my app')) : @js(__('I lost my phone: use a recovery code'))"></button>
                @endif
            </div>
            <p class="muted" style="text-align:center;margin:0"><a href="{{ route('client.login') }}">{{ __('Back to sign in') }}</a></p>
        </div>
    </div>
@endsection
