<x-layouts.guest :title="__('Staff sign in')" :subtitle="setting('company.name')">
    <x-demo-sign-in :action="route('admin.login')" :email="\App\Support\Demo::ADMIN_EMAIL" />
    <form method="POST" action="{{ route('admin.login') }}" style="display:grid;gap:1rem">
        @csrf
        <x-input name="email" type="email" :label="__('Email')" required autofocus autocomplete="username" />
        <x-input name="password" type="password" :label="__('Password')" required autocomplete="current-password" />
        <x-checkbox name="remember" :label="__('Keep me signed in on this device')" />
        <button class="btn btn-primary btn-block" type="submit">{{ __('Sign in') }}</button>
    </form>
    <x-slot:footer>
        <a href="{{ route('admin.password.request') }}">{{ __('Forgot your password?') }}</a>
    </x-slot:footer>
</x-layouts.guest>
