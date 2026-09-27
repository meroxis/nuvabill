<x-layouts.guest :title="__('Your company and account')" :subtitle="__('Step 3 of 3 · You can change all of this later in Settings.')" :wide="true">
    <form method="POST" action="{{ route('install.finish') }}" style="display:grid;gap:1.1rem">
        @csrf
        <div class="form-grid">
            <x-input name="company_name" :label="__('Company name')" required :placeholder="__('For example YourHost')" />
            <x-input name="company_email" type="email" :label="__('Billing email')" required :help="__('Shown on invoices. Order alerts go here.')" />
            <x-select name="currency" :label="__('Currency')" :options="$currencies" value="USD" required />
            <div></div>
            <x-input name="name" :label="__('Your name')" required autocomplete="name" />
            <x-input name="email" type="email" :label="__('Your staff email')" required autocomplete="username" />
            <x-input name="password" type="password" :label="__('Password')" :help="__('At least 10 characters.')" required autocomplete="new-password" />
            <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
            @unless (config('nuvabill.marketplace.store'))
                <x-checkbox name="demo_products" :label="__('Add example hosting plans to the store')" :help="__('Three sample plans you can edit or delete.')" :checked="true" class="span-2" />
            @endunless
        </div>
        <button class="btn btn-primary btn-block" type="submit">{{ __('Finish installing') }}</button>
    </form>
</x-layouts.guest>
