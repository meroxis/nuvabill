@extends('theme::layouts.app')

@section('title', __('Submit a new item'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('developer.dashboard') }}">{{ __('Developer account') }}</a></p>
            <h1 style="margin-top:.3rem">{{ __('Submit a new item') }}</h1>
            <p>{{ __('Fill in one page. We check it, test it, and tell you by email.') }}</p>
        </div>
    </div>

    <form method="POST" action="{{ route('developer.items.store') }}" enctype="multipart/form-data" class="two-col">
        @csrf
        <div style="display:grid;gap:16px">
            <section class="card" style="display:grid;gap:1rem">
                <h2 style="font-size:1.05rem">{{ __('1. Package') }}</h2>
                <div class="field">
                    <label for="f-package">{{ __('Package zip') }} <span aria-hidden="true">*</span></label>
                    <input id="f-package" class="input" type="file" name="package" accept=".zip" required>
                    @error('package')<p class="error">{{ $message }}</p>@else<p class="help">{{ __('A zip with theme.json, orderform.json or extension.json at the top. Up to 20 MB.') }}</p>@enderror
                </div>
                <x-textarea name="changelog" :label="__('What is new')" rows="2" />
            </section>

            @include('theme::developer.partials.listing-fields', ['item' => $item, 'new' => true])
        </div>

        <aside class="card summary">
            <h2 style="font-size:1.05rem">{{ __('What happens next') }}</h2>
            <ol class="dev-next">
                <li><b>{{ __('Automatic checks') }}</b>{{ __('Done in a few seconds.') }}</li>
                <li><b>{{ __('A person tests it') }}</b>{{ __('We install it on a test site and try every screen.') }}</li>
                <li><b>{{ __('We sign it') }}</b>{{ __('Approved packages are signed by the marketplace.') }}</li>
                <li><b>{{ __('It goes live') }}</b>{{ __('You get :share% of each sale.', ['share' => $share]) }}</li>
            </ol>
            <x-checkbox name="own_code" :label="__('I own this code and agree to the developer agreement')" required />
            <button class="btn btn-primary btn-block" type="submit">{{ __('Send for review') }}</button>
        </aside>
    </form>
@endsection
