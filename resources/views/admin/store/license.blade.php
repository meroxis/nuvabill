<x-layouts.admin :title="$license->publicId()">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.store.licenses.index') }}">{{ __('Licenses') }}</a></p>
            <h1 class="mono">{{ $license->publicId() }}</h1>
            <p>{{ $license->item->name }} · <a href="{{ route('admin.clients.show', $license->client) }}">{{ $license->client->name }}</a> · {{ $license->client->email }}</p>
        </div>
    </div>

    @if ($shared)
        <div class="flash" data-tone="warn"><span>{{ __('This key was used on :count different sites in the last 30 days. It may have been shared or leaked.', ['count' => count($recentSites)]) }}</span></div>
    @endif

    <div class="grid-2" style="align-items:start">
        <section class="card" style="display:grid;gap:.8rem">
            <dl class="dl">
                <dt>{{ __('Key') }}</dt><dd class="mono">{{ $license->key }}</dd>
                <dt>{{ __('Status') }}</dt><dd>@if ($license->isActive())<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill tone="crit">{{ __('Revoked') }}</x-pill> {{ $license->revoked_reason }}@endif</dd>
                <dt>{{ __('Site') }}</dt><dd>{{ $license->site ?? __('Not used yet') }}</dd>
                <dt>{{ __('Updates until') }}</dt><dd>{{ $license->updates_until?->format('d M Y') ?? '—' }}</dd>
                <dt>{{ __('Last seen') }}</dt><dd>{{ $license->last_seen_at?->diffForHumans() ?? '—' }}</dd>
                <dt>{{ __('Sites, 30 days') }}</dt><dd>{{ $recentSites ? implode(', ', $recentSites) : '—' }}</dd>
                @if ($license->service)<dt>{{ __('Service') }}</dt><dd><a href="{{ route('admin.services.show', $license->service) }}">#{{ $license->service->id }}</a></dd>@endif
            </dl>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @if ($license->site)
                    <form method="POST" action="{{ route('admin.store.licenses.release', $license) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Free it from its site') }}</button></form>
                @endif
                @unless ($license->isActive())
                    <form method="POST" action="{{ route('admin.store.licenses.restore', $license) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Make it active again') }}</button></form>
                @endunless
            </div>
            @if ($license->isActive())
                <form method="POST" action="{{ route('admin.store.licenses.revoke', $license) }}" style="display:grid;gap:.6rem;padding-top:.8rem;border-top:1px solid var(--nb-line)" onsubmit="return confirm(@js(__('Revoke this license?')))">
                    @csrf
                    <x-input name="reason" :label="__('Revoke it: reason')" required :placeholder="__('For example: found on a nulled site')" />
                    <button class="btn btn-sm" type="submit" style="justify-self:start;color:var(--nb-crit)">{{ __('Revoke license') }}</button>
                </form>
            @endif
        </section>

        <section class="card card-flush">
            <div class="card-header"><h2>{{ __('Recent checks and downloads') }}</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('When') }}</th><th>{{ __('Site') }}</th><th>{{ __('IP') }}</th><th>{{ __('Match') }}</th></tr></thead>
                <tbody>
                @forelse ($checks as $check)
                    <tr><td>{{ $check->created_at->diffForHumans(short: true) }}</td><td>{{ $check->site }}</td><td class="mono">{{ $check->ip }}</td><td>@if ($check->matched)<x-pill tone="good">{{ __('Yes') }}</x-pill>@else<x-pill tone="crit">{{ __('Other site') }}</x-pill>@endif</td></tr>
                @empty
                    <tr><td colspan="4"><div class="empty">{{ __('Not used yet.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>
    </div>
</x-layouts.admin>
