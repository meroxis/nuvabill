@extends('theme::layouts.app')

@section('title', __('Reset your password'))

@section('content')
    <div class="auth-wrap">
        <div class="auth-box">
            <h1 style="font-size:1.6rem">{{ __('Reset your password') }}</h1>
            <form method="POST" action="{{ route('client.password.email') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                <p class="muted" style="margin:0">{{ __('Enter your email. We will send you a link to choose a new password.') }}</p>
                <x-input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" />
                <x-captcha form="password_reset" />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Send reset link') }}</button>
            </form>
            <p class="muted" style="text-align:center;margin:0"><a href="{{ route('client.login') }}">{{ __('Back to sign in') }}</a></p>
        </div>
    </div>
@endsection
