<x-layouts.guest :title="__('Two-factor check')" :subtitle="__('Open your authenticator app and enter the 6-digit code for :name.', ['name' => setting('company.name')])">
    <div x-data="{ recovery: false }" style="display:grid;gap:1rem">
        <form method="POST" action="{{ route('admin.two-factor.challenge') }}" style="display:grid;gap:1rem">
            @csrf
            <template x-if="! recovery">
                <x-input name="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus />
            </template>
            <template x-if="recovery">
                <x-input name="recovery_code" :label="__('Recovery code')" :help="__('One of the codes you saved when you turned on two-factor login.')" autocomplete="off" />
            </template>
            <button class="btn btn-primary btn-block" type="submit">{{ __('Continue') }}</button>
        </form>
        <button type="button" class="btn btn-ghost btn-sm" @click="recovery = ! recovery"
            x-text="recovery ? @js(__('Use a code from my app')) : @js(__('I lost my phone: use a recovery code'))"></button>
    </div>
    <x-slot:footer>
        <a href="{{ route('admin.login') }}">{{ __('Back to sign in') }}</a>
    </x-slot:footer>
</x-layouts.guest>
