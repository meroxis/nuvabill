@extends('theme::layouts.app')

@section('title', __('Create an account'))

@section('content')
    <div class="auth-wrap">
        <div class="auth-box" style="width:min(620px,100%)">
            <h1 style="font-size:1.6rem">{{ __('Create your account') }}</h1>
            @include('theme::auth.social-buttons')
            <form method="POST" action="{{ route('client.register') }}" class="card" style="display:grid;gap:1.1rem">
                @csrf
                <div class="form-grid">
                    <x-input name="first_name" :label="__('First name')" required autocomplete="given-name" />
                    <x-input name="last_name" :label="__('Last name')" required autocomplete="family-name" />
                    <x-input name="email" type="email" :label="__('Email')" required autocomplete="email" />
                    <x-input name="phone" type="tel" :label="__('Phone')" autocomplete="tel" />
                    <x-input name="company_name" :label="__('Company (optional)')" autocomplete="organization" />
                    <x-select name="country" :label="__('Country')" :options="$countries" :placeholder="__('Choose your country')" required />
                    <x-input name="password" type="password" :label="__('Password')" :help="__('At least 8 characters.')" required autocomplete="new-password" />
                    <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
                </div>
                <x-captcha form="client_register" />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Create account') }}</button>
            </form>
            <p class="muted" style="text-align:center;margin:0">{{ __('Already have an account?') }} <a href="{{ route('client.login') }}">{{ __('Sign in') }}</a></p>
        </div>
    </div>
@endsection
