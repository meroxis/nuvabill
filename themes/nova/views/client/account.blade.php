@extends('theme::layouts.app')

@section('title', __('Account'))

@section('content')
    <div class="page-title"><div><h1>{{ __('Your details') }}</h1><p>{{ __('These appear on your invoices.') }}</p></div></div>

    <div class="two-col">
        <form method="POST" action="{{ route('client.account.update') }}" class="card" style="display:grid;gap:1.1rem">
            @csrf
            @method('PUT')
            <div class="form-grid">
                <x-input name="first_name" :label="__('First name')" :value="$client->first_name" required autocomplete="given-name" />
                <x-input name="last_name" :label="__('Last name')" :value="$client->last_name" required autocomplete="family-name" />
                <x-input name="email" type="email" :label="__('Email')" :value="$client->email" required autocomplete="email" />
                <x-input name="phone" type="tel" :label="__('Phone')" :value="$client->phone" autocomplete="tel" />
                <x-input name="company_name" :label="__('Company')" :value="$client->company_name" class="span-2" autocomplete="organization" />
                <x-input name="address_1" :label="__('Address')" :value="$client->address_1" autocomplete="address-line1" />
                <x-input name="address_2" :label="__('Address line 2')" :value="$client->address_2" autocomplete="address-line2" />
                <x-input name="city" :label="__('City')" :value="$client->city" autocomplete="address-level2" />
                <x-input name="state" :label="__('State or region')" :value="$client->state" autocomplete="address-level1" />
                <x-input name="postcode" :label="__('Postcode')" :value="$client->postcode" autocomplete="postal-code" />
                <x-select name="country" :label="__('Country')" :options="$countries" :value="$client->country" required />
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save details') }}</button></div>
        </form>

        <form method="POST" action="{{ route('client.account.password') }}" class="card" style="display:grid;gap:1rem">
            @csrf
            @method('PUT')
            <h2 style="font-size:1.05rem">{{ __('Change password') }}</h2>
            <x-input name="current_password" type="password" :label="__('Current password')" required autocomplete="current-password" />
            <x-input name="password" type="password" :label="__('New password')" required autocomplete="new-password" />
            <x-input name="password_confirmation" type="password" :label="__('Type it again')" required autocomplete="new-password" />
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Change password') }}</button></div>
        </form>
    </div>
@endsection
