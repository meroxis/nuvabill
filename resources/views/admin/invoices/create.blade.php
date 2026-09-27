<x-layouts.admin :title="__('New invoice')">
    <div class="page-head">
        <div>
            <h1>{{ __('New invoice') }}</h1>
            <p>{{ __('For one-off charges. Renewals are created automatically.') }}</p>
        </div>
    </div>

    @php
        $oldItems = collect(old('items', [['description' => '', 'amount' => '', 'taxed' => true]]))->map(fn ($item) => ['description' => $item['description'] ?? '', 'amount' => $item['amount'] ?? '', 'taxed' => (bool) ($item['taxed'] ?? true)])->all();
    @endphp
    <form method="POST" action="{{ route('admin.invoices.store') }}" class="card" style="display:grid;gap:1.25rem"
          x-data="{ items: @js(array_values($oldItems)), total() { return this.items.reduce((sum, item) => sum + (parseFloat(item.amount) || 0), 0).toFixed(2) } }">
        @csrf
        <div class="form-grid">
            <x-input name="client" :label="__('Client ID or email')" :value="$client?->id" :help="$client ? __('Invoice for :name', ['name' => $client->name]) : null" required />
            <x-input name="due_at" type="date" :label="__('Due date')" :value="today()->addDays((int) setting('billing.payment_terms_days'))->toDateString()" required />
        </div>

        <div style="display:grid;gap:.6rem">
            <span class="label">{{ __('Lines') }}</span>
            <template x-for="(item, index) in items" :key="index">
                <div style="display:grid;grid-template-columns:minmax(0,1fr) 140px auto auto;gap:8px;align-items:center">
                    <input class="input" :name="`items[${index}][description]`" x-model="item.description" placeholder="{{ __('What is this charge for?') }}" required aria-label="{{ __('Description') }}">
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
                <span>{{ __('Total') }}: <b class="num" x-text="total()"></b> {{ setting('billing.currency') }}</span>
            </div>
        </div>

        <x-textarea name="notes" :label="__('Note for the client')" rows="2" />

        <div style="display:flex;flex-wrap:wrap;gap:1.2rem">
            <x-checkbox name="send_email" :label="__('Email the invoice to the client')" :checked="true" />
            <x-checkbox name="draft" :label="__('Save as draft (the client cannot see it yet)')" />
        </div>

        <div class="form-actions">
            <button class="btn btn-primary" type="submit">{{ __('Create invoice') }}</button>
            <a class="btn" href="{{ route('admin.invoices.index') }}">{{ __('Cancel') }}</a>
        </div>
    </form>
</x-layouts.admin>
