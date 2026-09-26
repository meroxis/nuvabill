@props(['action', 'email'])
{{-- One-click sign-in with the shared demo account. Shown only on the public demo site. --}}
@if (\App\Support\Demo::isEnabled())
    <form method="POST" action="{{ $action }}" class="flash" data-tone="info" style="display:grid;gap:.6rem">
        @csrf
        <input type="hidden" name="email" value="{{ $email }}">
        <input type="hidden" name="password" value="{{ \App\Support\Demo::PASSWORD }}">
        <span>{{ __('Demo account') }}: <b>{{ $email }}</b> / <b>{{ \App\Support\Demo::PASSWORD }}</b></span>
        <button class="btn btn-primary btn-block" type="submit">{{ __('Sign in to the demo') }}</button>
    </form>
@endif
