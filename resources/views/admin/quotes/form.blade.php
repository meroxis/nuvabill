@php $editing = $quote->exists; @endphp
<x-layouts.admin :title="$editing ? __('Edit quote') : __('New quote')">
    <div class="page-head">
        <div>
            <h1>{{ $editing ? __('Edit quote :number', ['number' => $quote->displayNumber()]) : __('New quote') }}</h1>
            <p>{{ __('Tax follows the client\'s country, like on invoices.') }}</p>
        </div>
    </div>

    @php
        $oldItems = collect(old('items', $items))->map(fn ($item) => ['description' => $item['description'] ?? '', 'amount' => $item['amount'] ?? '', 'taxed' => (bool) ($item['taxed'] ?? true)])->all();
    @endphp
    <form method="POST" action="{{ $editing ? route('admin.quotes.update', $quote) : route('admin.quotes.store') }}" class="card" style="display:grid;gap:1.25rem"
          x-data="{ items: @js(array_values($oldItems)), total() { return this.items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0).toFixed(2) } }">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="form-grid">
            <x-input name="client" :label="__('Client ID or email')" :value="$client?->id" :help="$client ? __('Quote for :name', ['name' => $client->name]) : null" required />
            <x-input name="valid_until" type="date" :label="__('Valid until')" :value="$quote->valid_until?->toDateString()" required />
            <x-input name="subject" :label="__('Subject')" :value="$quote->subject" required class="span-2" :placeholder="__('For example Dedicated server with managed backups')" />
        </div>

        <div style="display:grid;gap:.6rem">
            <span class="label">{{ __('Lines') }}</span>
            <template x-for="(item, index) in items" :key="index">
                <div style="display:grid;grid-template-columns:minmax(0,1fr) 140px auto auto;gap:8px;align-items:center">
                    <input class="input" :name="`items[${index}][description]`" x-model="item.description" placeholder="{{ __('What is this for?') }}" required aria-label="{{ __('Description') }}">
                    <input class="input num" type="number" step="0.01" :name="`items[${index}][amount]`" x-model="item.amount" placeholder="0.00" required aria-label="{{ __('Amount') }}">
                    @if (setting('tax.enabled'))
                        <label class="check" style="white-space:nowrap"><input type="hidden" :name="`items[${index}][taxed]`" value="0"><input type="checkbox" :name="`items[${index}][taxed]`" value="1" x-model="item.taxed"><span>{{ __('Taxed') }}</span></label>
                    @else
                        <span></span>
                    @endif
                    <button type="button" class="icon-btn" @click="items.splice(index, 1)" x-show="items.length > 1" aria-label="{{ __('Remove line') }}"><x-icon name="x" /></button>
                </div>
            </template>
            @error('items.*.description')<p class="error" style="color:var(--nb-crit);font-size:.8rem;margin:0">{{ $message }}</p>@enderror
            @error('items.*.amount')<p class="error" style="color:var(--nb-crit);font-size:.8rem;margin:0">{{ $message }}</p>@enderror
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <button type="button" class="btn btn-sm" @click="items.push({ description: '', amount: '', taxed: true })"><x-icon name="plus" />{{ __('Add line') }}</button>
                <span>{{ __('Before tax') }}: <b class="num" x-text="total()"></b> {{ $client?->currency ?? setting('billing.currency') }}</span>
            </div>
        </div>

        <x-textarea name="notes" :label="__('Note for the client')" :value="$quote->notes" rows="3" :help="__('For example what is included, delivery time or payment terms.')" />
        <x-textarea name="admin_notes" :label="__('Staff notes')" :value="$quote->admin_notes" rows="2" :help="__('Only staff can see these notes.')" />

        <div class="form-actions">
            <button class="btn btn-primary" type="submit" name="send" value="1"><x-icon name="mail" />{{ __('Save and send') }}</button>
            <button class="btn" type="submit" name="send" value="0">{{ $quote->status?->value === 'sent' ? __('Save changes') : __('Save as draft') }}</button>
            <a class="btn btn-ghost" href="{{ $editing ? route('admin.quotes.show', $quote) : route('admin.quotes.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
