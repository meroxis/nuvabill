@extends('theme::layouts.app')

@section('title', __('Sign in'))

@section('content')
    <div class="auth-wrap">
        <div class="auth-box">
            <h1 style="font-size:1.6rem">{{ __('Sign in to your account') }}</h1>
            <x-demo-sign-in :action="route('client.login')" :email="\App\Support\Demo::CLIENT_EMAIL" />
            @include('theme::auth.social-buttons')
            <form method="POST" action="{{ route('client.login') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                <x-input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" />
                <x-input name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
                <x-checkbox name="remember" :label="__('Keep me signed in')" />
                <x-captcha form="client_login" />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Sign in') }}</button>
                <a href="{{ route('client.password.request') }}" style="font-size:.88rem">{{ __('Forgot your password?') }}</a>
            </form>
            <p class="muted" style="text-align:center;margin:0">{{ __('New customer?') }} <a href="{{ route('client.register') }}">{{ __('Create an account') }}</a></p>
        </div>
    </div>
@endsection
