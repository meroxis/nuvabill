<x-layouts.admin :title="$item->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.store.items.index') }}">{{ __('Marketplace items') }}</a></p>
            <h1>{{ $item->name }}</h1>
            <p>{{ $item->type->label() }} · {{ __('by :name', ['name' => $item->developer->name]) }} · {{ __('version :version', ['version' => $item->latestVersion?->version ?? '—']) }}</p>
        </div>
        @if ($item->isLive())<a class="btn" href="{{ route('marketplace.show', $item) }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('View listing') }}</a>@endif
    </div>

    @if ($item->pending_listing)
        {{-- Listing changes on an approved item wait here, so a reviewed item cannot change what buyers read on its own. --}}
        @php
            $labels = ['name' => __('Name'), 'summary' => __('Short description'), 'description' => __('Full description'), 'demo_url' => __('Demo link'), 'docs_url' => __('Help page link'), 'screenshots' => __('Screenshots')];
            // New screenshots are not public yet, so staff open them through a staff-only link.
            $show = fn (string $field, mixed $value, bool $waiting = false) => $field === 'screenshots'
                ? collect((array) $value)->map(fn ($file) => '<a href="'.e($waiting ? route('admin.store.items.media', [$item, basename((string) $file)]) : route('marketplace.media', [$item->slug, basename((string) $file)])).'" target="_blank" rel="noopener">'.e(basename((string) $file)).'</a>')->implode(', ')
                : e((string) $value);
        @endphp
        <section class="card" style="display:grid;gap:.8rem;max-width:760px;margin-bottom:16px">
            <h2 style="font-size:1rem">{{ __('Listing changes waiting for review') }}</h2>
            <p class="muted" style="margin:0">{{ __('The developer changed what buyers read. Check the new texts and links before they go live.') }}</p>
            <div class="table-wrap"><table class="table">
                <thead><tr><th></th><th>{{ __('Now') }}</th><th>{{ __('New') }}</th></tr></thead>
                <tbody>
                @foreach (array_intersect_key($item->pending_listing, $labels) as $field => $value)
                    <tr>
                        <td>{{ $labels[$field] }}</td>
                        <td class="muted" style="overflow-wrap:anywhere">{!! $show($field, $item->getAttribute($field)) !!}</td>
                        <td style="overflow-wrap:anywhere"><b>{!! $show($field, $value, true) !!}</b></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            <div style="display:flex;gap:8px;flex-wrap:wrap">
                @foreach (['approve' => __('Approve changes'), 'discard' => __('Turn down')] as $decision => $label)
                    <form method="POST" action="{{ route('admin.store.items.listing', $item) }}">
                        @csrf
                        <input type="hidden" name="decision" value="{{ $decision }}">
                        <input type="hidden" name="seen" value="{{ \App\Http\Controllers\Marketplace\Admin\ItemController::fingerprint($item) }}">
                        <button class="btn {{ $decision === 'approve' ? 'btn-primary' : '' }}" type="submit">{{ $label }}</button>
                    </form>
                @endforeach
            </div>
        </section>
    @endif

    <form method="POST" action="{{ route('admin.store.items.update', $item) }}" class="card" style="display:grid;gap:1rem;max-width:760px">
        @csrf
        @method('PUT')
        <div class="form-grid">
            <x-select name="status" :label="__('Status')" :options="['draft' => __('Draft'), 'live' => __('Live'), 'hidden' => __('Hidden')]" :value="$item->status" required :help="__('Live items need an approved version.')" />
            <x-select name="category" :label="__('Category')" :options="array_combine($categories, $categories)" :value="$item->category" :placeholder="__('None')" />
            <x-input name="price" type="number" step="0.01" min="0" :label="__('Price (:currency)', ['currency' => $item->currency])" :value="\App\Support\Money::toDecimal($item->price)" required />
            <x-input name="update_price" type="number" step="0.01" min="0" :label="__('Yearly updates')" :value="\App\Support\Money::toDecimal($item->update_price)" />
            <x-input name="demo_url" type="url" :label="__('Demo link')" :value="$item->demo_url" class="span-2" />
        </div>
        <x-checkbox name="is_featured" :label="__('Feature it at the top of the marketplace')" :checked="$item->is_featured" />
        <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
    </form>
</x-layouts.admin>
