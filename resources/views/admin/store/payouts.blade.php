<x-layouts.admin :title="__('Payouts and commission')">
    <div class="page-head"><div><h1>{{ __('Marketplace store') }}</h1></div></div>
    @include('admin.store.nav')

    <div class="kpis" style="grid-template-columns:repeat(3,minmax(0,1fr))">
        <div class="kpi"><small>{{ __('Marketplace sales this month') }}</small><b>{{ money($salesThisMonth, $currency) }}</b></div>
        <div class="kpi"><small>{{ __('Your fees this month') }}</small><b>{{ money($feesThisMonth, $currency) }}</b></div>
        <div class="kpi"><small>{{ __('Owed to developers') }}</small><b>{{ money((int) $owed->sum('owed'), $currency) }}</b></div>
    </div>

    <div class="grid-2" style="grid-template-columns:minmax(0,1fr) 340px;align-items:start">
        <section class="card card-flush">
            <div class="card-header"><h2>{{ __('Owed to developers') }}</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Developer') }}</th><th>{{ __('Pay to') }}</th><th class="end">{{ __('Sales') }}</th><th class="end">{{ __('Owed') }}</th><th></th></tr></thead>
                <tbody>
                @forelse ($developers as $developer)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.store.developers.edit', $developer) }}">{{ $developer->name }}</a></td>
                        <td>{{ \App\Http\Controllers\Marketplace\DeveloperController::PAYOUT_METHODS[$developer->payout_method] ?? __('Not set') }}</td>
                        <td class="end num">{{ $owed[$developer->id]->sales }}</td>
                        <td class="end num"><b>{{ money((int) $owed[$developer->id]->owed, $currency) }}</b></td>
                        <td class="end">
                            <form method="POST" action="{{ route('admin.store.payouts.store') }}" style="display:flex;gap:6px;justify-content:flex-end" onsubmit="return confirm(@js(__('Did you already send :amount to :name?', ['amount' => money((int) $owed[$developer->id]->owed, $currency), 'name' => $developer->name])))">
                                @csrf
                                <input type="hidden" name="developer_id" value="{{ $developer->id }}">
                                <input class="input" name="reference" placeholder="{{ __('Transfer reference') }}" aria-label="{{ __('Transfer reference') }}" style="max-width:170px;height:34px">
                                <button class="btn btn-sm btn-primary" type="submit">{{ __('Mark paid') }}</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5"><div class="empty">{{ __('Nobody is owed anything right now.') }}</div></td></tr>
                @endforelse
                </tbody>
            </table></div>
        </section>

        <div style="display:grid;gap:14px">
            <form method="POST" action="{{ route('admin.store.commission') }}" class="card" style="display:grid;gap:.8rem">
                @csrf
                @method('PUT')
                <h2 style="font-size:1rem">{{ __('Commission') }}</h2>
                <x-input name="share" type="number" min="0" max="100" :label="__('Developers keep (%)')" :value="$share" required :help="__('The marketplace keeps the rest. You can give one developer their own share on their page.')" />
                <button class="btn" type="submit">{{ __('Save') }}</button>
            </form>

            <section class="card" style="display:grid;gap:.5rem">
                <h2 style="font-size:1rem">{{ __('Recent payouts') }}</h2>
                @forelse ($payouts as $payout)
                    <div class="summary-row"><span>{{ $payout->developer->name }}<br><span class="faint" style="font-size:.8rem">{{ $payout->paid_at?->translatedFormat('d M Y') }} {{ $payout->reference }}</span></span><span class="num">{{ money($payout->amount, $payout->currency) }}</span></div>
                @empty
                    <p class="muted" style="margin:0">{{ __('None yet.') }}</p>
                @endforelse
            </section>
        </div>
    </div>
</x-layouts.admin>
