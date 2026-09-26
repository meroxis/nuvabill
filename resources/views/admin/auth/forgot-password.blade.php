<x-layouts.guest :title="__('Reset your password')" :subtitle="__('Enter your staff email. We will send you a link to choose a new password.')">
    <form method="POST" action="{{ route('admin.password.email') }}" style="display:grid;gap:1rem">
        @csrf
        <x-input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" />
        <button class="btn btn-primary btn-block" type="submit">{{ __('Send reset link') }}</button>
    </form>
    <x-slot:footer>
        <a href="{{ route('admin.login') }}">{{ __('Back to sign in') }}</a>
    </x-slot:footer>
</x-layouts.guest>
