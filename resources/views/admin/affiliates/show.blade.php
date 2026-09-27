<x-layouts.admin :title="__('Affiliate :name', ['name' => $affiliate->client->name])">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Affiliate') }}</p>
            <h1 style="margin-top:.2rem">{{ $affiliate->client->name }} @if ($affiliate->isActive())<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill>{{ __('Paused') }}</x-pill>@endif</h1>
            <p class="mono">{{ $affiliate->link() }}</p>
        </div>
        <a class="btn" href="{{ route('admin.clients.show', $affiliate->client) }}">{{ __('Open client') }}</a>
    </div>

    <div class="kpis">
        <div class="kpi"><small>{{ __('Visits') }}</small><b>{{ number_format($affiliate->clicks) }}</b><span>{{ trans_choice(':count sign-up|:count sign-ups', $referrals->count(), ['count' => $referrals->count()]) }}</span></div>
        <div class="kpi"><small>{{ __('On hold') }}</small><b>{{ money($totals['pending'], $affiliate->client->currency) }}</b><span>{{ __(':days days', ['days' => (int) setting('affiliates.hold_days')]) }}</span></div>
        <div class="kpi"><small>{{ __('Available') }}</small><b>{{ money($totals['available'], $affiliate->client->currency) }}</b><span>{{ __('Not moved to the wallet yet') }}</span></div>
        <div class="kpi"><small>{{ __('Paid') }}</small><b>{{ money($totals['paid'], $affiliate->client->currency) }}</b><span>{{ __('Rate: :percent%', ['percent' => rtrim(rtrim(number_format($affiliate->rate(), 2, '.', ''), '0'), '.')]) }}</span></div>
    </div>

    <div class="grid-2" style="align-items:start">
        <section class="card card-flush">
            <div class="card-header"><h2>{{ __('Commissions') }}</h2></div>
            @if ($commissions->isEmpty())
                <div class="empty">{{ __('No commissions yet.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Client') }}</th><th>{{ __('Invoice') }}</th><th class="end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($commissions as $commission)
                        <tr>
                            <td>{{ $commission->client->name }}</td>
                            <td>@if ($commission->invoice)<a class="row-link mono" href="{{ route('admin.invoices.show', $commission->invoice) }}">{{ $commission->invoice->displayNumber() }}</a>@endif</td>
                            <td class="end num">{{ money($commission->amount, $commission->currency) }}</td>
                            <td><span class="pill" data-tone="{{ $commission->tone() }}">{{ $commission->statusLabel() }}</span></td>
                            <td style="white-space:nowrap">
                                @foreach (['release' => __('Release now'), 'paid' => __('Mark paid'), 'cancel' => __('Cancel')] as $action => $label)
                                    @if (($action === 'release' && $commission->status === 'pending') || ($action === 'paid' && $commission->status === 'available') || ($action === 'cancel' && in_array($commission->status, ['pending', 'available'], true)))
                                        <form method="POST" action="{{ route('admin.affiliates.commission', [$commission, $action]) }}" style="display:inline" @if ($action === 'cancel') data-confirm="{{ __('Cancel this commission?') }}" @endif>
                                            @csrf
                                            <button class="btn btn-sm {{ $action === 'cancel' ? 'btn-ghost' : '' }}" type="submit">{{ $label }}</button>
                                        </form>
                                    @endif
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                {{ $commissions->links() }}
            @endif
        </section>

        <div style="display:grid;gap:14px">
            <form method="POST" action="{{ route('admin.affiliates.update', $affiliate) }}" class="card" style="display:grid;gap:1rem">
                @csrf
                @method('PUT')
                <x-select name="status" :label="__('Status')" :options="['active' => __('Active'), 'suspended' => __('Paused: earns nothing')]" :value="$affiliate->status" required />
                <x-input name="percent" type="number" step="0.01" min="0" max="100" :label="__('Own commission %')" :value="$affiliate->percent" :help="__('Leave empty to use :percent% from the program settings.', ['percent' => setting('affiliates.percent')])" />
                <div><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
            </form>

            <section class="card">
                <div class="card-header"><h2>{{ __('Referred clients') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($referrals as $referral)
                        <li class="feed-item"><a class="row-link" href="{{ route('admin.clients.show', $referral->client) }}">{{ $referral->client->name }}</a><time>{{ $referral->created_at->translatedFormat('d M Y') }}</time></li>
                    @empty
                        <li class="muted">{{ __('Nobody signed up through this link yet.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-layouts.admin>
