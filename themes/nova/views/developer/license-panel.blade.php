{{-- On the client's service page for a marketplace purchase: the license key and how to use it. --}}
<section class="card" style="display:grid;gap:.9rem;margin-bottom:18px">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <h2 style="font-size:1.05rem">{{ __('Your :item license', ['item' => $license->item->name]) }}</h2>
        @if ($license->isActive())<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill tone="crit">{{ __('Cancelled') }}</x-pill>@endif
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <code class="mono dev-key">{{ $license->key }}</code>
        <button type="button" class="btn btn-sm" data-copy="{{ $license->key }}" data-copied="{{ __('Copied') }}">{{ __('Copy') }}</button>
    </div>
    <dl class="dl" style="margin:0">
        <dt>{{ __('Site') }}</dt><dd>{{ $license->site ?? __('Not used yet. It is tied to the first site you install with.') }}</dd>
        <dt>{{ __('Updates until') }}</dt><dd>{{ $license->updates_until?->translatedFormat('d M Y') ?? __('Always') }}</dd>
    </dl>
    <ol class="dev-next" style="margin:0">
        <li><b>{{ __('Open your Nuvabill admin') }}</b>{{ __('Go to Marketplace and find :item.', ['item' => $license->item->name]) }}</li>
        <li><b>{{ __('Paste the key and click Install') }}</b>{{ __('Nuvabill downloads it, checks the signature and installs it.') }}</li>
    </ol>
    @if ($license->site && $license->isActive())
        <form method="POST" action="{{ route('client.licenses.move', $license) }}" onsubmit="return confirm(@js(__('Free this key from :site so you can use it on another site?', ['site' => $license->site])))">
            @csrf
            <button class="btn btn-sm" type="submit">{{ __('Move to another site') }}</button>
        </form>
    @endif
</section>
