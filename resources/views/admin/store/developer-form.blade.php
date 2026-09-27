<x-layouts.admin :title="$developer->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.store.developers.index') }}">{{ __('Developers') }}</a></p>
            <h1>{{ $developer->name }}</h1>
            <p>{{ $developer->website }}@if ($developer->client) · {{ $developer->client->email }}@endif</p>
        </div>
    </div>

    <div class="grid-2" style="align-items:start">
        <form method="POST" action="{{ route('admin.store.developers.update', $developer) }}" class="card" style="display:grid;gap:1rem">
            @csrf
            @method('PUT')
            <x-checkbox name="is_verified" :label="__('Verified developer (a badge on their items)')" :checked="$developer->is_verified" />
            <x-checkbox name="is_official" :label="__('Official (made by us)')" :checked="$developer->is_official" />
            <x-input name="share_percent" type="number" min="0" max="100" :label="__('Their share (%)')" :value="$developer->share_percent" :help="__('Empty uses the default: :share%.', ['share' => $share])" />
            <x-select name="status" :label="__('Status')" :options="['active' => __('Active'), 'suspended' => __('Suspended')]" :value="$developer->status" required :help="__('Suspended developers cannot send new versions.')" />
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
        </form>

        <section class="card" style="display:grid;gap:.6rem">
            <h2 style="font-size:1rem">{{ __('Payout details') }}</h2>
            <dl class="dl">
                <dt>{{ __('Method') }}</dt><dd>{{ \App\Http\Controllers\Marketplace\DeveloperController::PAYOUT_METHODS[$developer->payout_method] ?? '—' }}</dd>
                <dt>{{ __('Details') }}</dt><dd style="white-space:pre-line">{{ $developer->payout_details ?: '—' }}</dd>
                <dt>{{ __('Owed now') }}</dt><dd class="num">{{ money($developer->balance($currency), $currency) }}</dd>
            </dl>
            <h2 style="font-size:1rem;margin-top:.6rem">{{ __('Items') }}</h2>
            @forelse ($developer->items as $item)
                <a href="{{ route('admin.store.items.edit', $item) }}">{{ $item->name }}</a>
            @empty
                <p class="muted" style="margin:0">{{ __('No items yet.') }}</p>
            @endforelse
        </section>
    </div>
</x-layouts.admin>
