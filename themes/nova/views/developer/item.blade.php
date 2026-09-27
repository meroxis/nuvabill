@extends('theme::layouts.app')

@section('title', $item->name)

@section('content')
    @php $current = $item->versions->first(); @endphp
    <div class="page-title">
        <div>
            <p class="eyebrow"><a href="{{ route('developer.dashboard') }}">{{ __('Developer account') }}</a></p>
            <h1 style="margin-top:.3rem">{{ $item->name }} @include('theme::developer.partials.status', ['version' => $current, 'item' => $item])</h1>
            <p>{{ $item->type->label() }} · <span class="mono">{{ $item->slug }}</span>@if ($item->isLive()) · <a href="{{ route('marketplace.show', $item) }}">{{ __('View in the marketplace') }}</a>@endif</p>
        </div>
    </div>

    <div class="two-col">
        <div style="display:grid;gap:16px">
            @foreach ($item->versions as $version)
                <section class="card" style="display:grid;gap:.8rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                        <h2 style="font-size:1.05rem">{{ __('Version :version', ['version' => $version->version]) }}</h2>
                        <x-pill :tone="match ($version->status) { 'approved' => 'good', 'changes' => 'warn', 'rejected' => 'crit', default => 'info' }">{{ match ($version->status) { 'approved' => __('Approved'), 'changes' => __('Changes asked'), 'rejected' => __('Not accepted'), default => __('In review') } }}</x-pill>
                    </div>
                    @if ($version->changelog)<p class="muted" style="margin:0">{{ $version->changelog }}</p>@endif
                    <div class="dev-checks">
                        @foreach ((array) $version->checks as $check)
                            <div data-level="{{ $check['level'] }}"><x-icon :name="$check['level'] === 'ok' ? 'check' : 'alert'" /><span><b>{{ $check['title'] }}</b><br><span class="muted">{{ $check['text'] }}</span></span></div>
                        @endforeach
                    </div>
                    @foreach ($version->messages as $message)
                        <div class="dev-message" data-from="{{ $message->author_type }}"><b>{{ $message->authorName() }}</b> <span class="muted" style="font-size:.8rem">{{ $message->created_at->diffForHumans() }}</span><br>{{ $message->message }}</div>
                    @endforeach
                    @if ($loop->first && in_array($version->status, [\App\Models\MarketplaceVersion::STATUS_PENDING, \App\Models\MarketplaceVersion::STATUS_CHANGES], true))
                        <form method="POST" action="{{ route('developer.versions.messages.store', $version) }}" style="display:grid;gap:.6rem">
                            @csrf
                            <x-textarea name="message" :label="__('Message to the review team')" rows="2" required />
                            <button class="btn btn-sm" type="submit" style="justify-self:start">{{ __('Send') }}</button>
                        </form>
                    @endif
                </section>
            @endforeach

            <form method="POST" action="{{ route('developer.items.update', $item) }}" enctype="multipart/form-data" style="display:grid;gap:16px">
                @csrf
                @method('PUT')
                @include('theme::developer.partials.listing-fields', ['item' => $item, 'new' => false])
                <button class="btn" type="submit" style="justify-self:start">{{ __('Save listing') }}</button>
            </form>
        </div>

        <aside style="display:grid;gap:16px;align-content:start">
            <form method="POST" action="{{ route('developer.items.versions.store', $item) }}" enctype="multipart/form-data" class="card summary">
                @csrf
                <h2 style="font-size:1.05rem">{{ __('Upload a new version') }}</h2>
                <div class="field">
                    <label for="f-package">{{ __('Package zip') }}</label>
                    <input id="f-package" class="input" type="file" name="package" accept=".zip" required>
                    @error('package')<p class="error">{{ $message }}</p>@else<p class="help">{{ __('Raise the version in your manifest first.') }}</p>@enderror
                </div>
                <x-textarea name="changelog" :label="__('What is new')" rows="2" />
                <button class="btn btn-primary btn-block" type="submit">{{ __('Send for review') }}</button>
            </form>
            <section class="card">
                <dl class="dl">
                    <dt>{{ __('Price') }}</dt><dd>{{ $item->isFree() ? __('Free') : money($item->price, $item->currency) }}</dd>
                    <dt>{{ __('Your share') }}</dt><dd>{{ $share }}%</dd>
                    <dt>{{ __('Sales') }}</dt><dd>{{ number_format($item->sales_count) }}</dd>
                    <dt>{{ __('Installs') }}</dt><dd>{{ number_format($item->installs_count) }}</dd>
                </dl>
            </section>
        </aside>
    </div>
@endsection
