<x-layouts.guest :title="__('Choose a new password')">
    <form method="POST" action="{{ route('admin.password.update') }}" style="display:grid;gap:1rem">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-input name="email" type="email" :label="__('Email')" :value="$email" required autocomplete="username" />
        <x-input name="password" type="password" :label="__('New password')" :help="__('At least 10 characters.')" required autocomplete="new-password" />
        <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
        <button class="btn btn-primary btn-block" type="submit">{{ __('Save password') }}</button>
    </form>
</x-layouts.guest>
