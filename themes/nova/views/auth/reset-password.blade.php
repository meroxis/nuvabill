@extends('theme::layouts.app')

@section('title', __('Choose a new password'))

@section('content')
    <div class="auth-wrap">
        <div class="auth-box">
            <h1 style="font-size:1.6rem">{{ __('Choose a new password') }}</h1>
            <form method="POST" action="{{ route('client.password.update') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <x-input name="email" type="email" :label="__('Email')" :value="$email" required autocomplete="username" />
                <x-input name="password" type="password" :label="__('New password')" :help="__('At least 8 characters.')" required autocomplete="new-password" />
                <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Save password') }}</button>
            </form>
        </div>
    </div>
@endsection
