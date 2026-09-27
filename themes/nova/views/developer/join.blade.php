@extends('theme::layouts.app')

@section('title', __('Become a developer'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('marketplace.developers') }}">{{ __('Developers') }}</a></p>
            <h1 style="margin-top:.3rem">{{ __('Become a developer') }}</h1>
            <p>{{ __('Sell themes and extensions on the marketplace. You keep :share% of every sale.', ['share' => $share]) }}</p>
        </div>
    </div>

    <div class="two-col">
        <form method="POST" action="{{ route('developer.join.store') }}" class="card" style="display:grid;gap:1rem">
            @csrf
            <x-input name="name" :label="__('Developer or company name')" :value="$client->company_name ?: $client->name" required maxlength="120" :help="__('Buyers see this on your items.')" />
            <x-input name="website" type="url" :label="__('Website')" placeholder="https://" />
            <x-textarea name="bio" :label="__('About you')" rows="3" :help="__('One or two sentences.')" />
            <x-select name="payout_method" :label="__('How we pay you')" :options="$methods" :placeholder="__('Choose later')" />
            <x-textarea name="payout_details" :label="__('Payout details')" rows="2" :help="__('For example your Wayl or FIB number, or bank account. Only staff who send payouts can see it.')" />
            <x-checkbox name="agree" :label="__('I agree to the developer agreement and the review rules')" />
            <button class="btn btn-primary" type="submit">{{ __('Create my developer account') }}</button>
        </form>

        <aside class="card summary">
            <h2 style="font-size:1.05rem">{{ __('How it works') }}</h2>
            <ul class="features">
                <li>{{ __('Upload a zip. Automatic checks run at once.') }}</li>
                <li>{{ __('A person installs and tests it, then approves it or asks for changes.') }}</li>
                <li>{{ __('Approved versions are signed and install in one click.') }}</li>
                <li>{{ __('We send license keys and take payments by card or wallet.') }}</li>
                <li>{{ __('You get :share% of each sale and renewal, paid monthly.', ['share' => $share]) }}</li>
            </ul>
        </aside>
    </div>
@endsection
