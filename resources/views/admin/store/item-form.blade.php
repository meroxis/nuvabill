<x-layouts.admin :title="$item->name">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.store.items.index') }}">{{ __('Marketplace items') }}</a></p>
            <h1>{{ $item->name }}</h1>
            <p>{{ $item->type->label() }} · {{ __('by :name', ['name' => $item->developer->name]) }} · {{ __('version :version', ['version' => $item->latestVersion?->version ?? '—']) }}</p>
        </div>
        @if ($item->isLive())<a class="btn" href="{{ route('marketplace.show', $item) }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('View listing') }}</a>@endif
    </div>

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
