<x-layouts.admin :title="__('Affiliates')">
    <div class="page-head">
        <div>
            <h1>{{ __('Affiliates') }}</h1>
            <p>{{ __('Clients share a link. When the people they send pay, they earn a commission.') }}</p>
        </div>
    </div>

    <div class="grid-2" style="align-items:start">
        <section class="card card-flush">
            @if ($affiliates->isEmpty())
                <div class="empty"><strong>{{ __('No affiliates yet') }}</strong>{{ __('Clients join from the Affiliate program page in their account.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Affiliate') }}</th><th>{{ __('Code') }}</th><th class="end">{{ __('Visits') }}</th><th class="end">{{ __('Sign-ups') }}</th><th class="end">{{ __('Earned') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($affiliates as $affiliate)
                        <tr>
                            <td><a class="row-link" href="{{ route('admin.affiliates.show', $affiliate) }}">{{ $affiliate->client->name }}</a></td>
                            <td class="mono">{{ $affiliate->code }}</td>
                            <td class="end num">{{ number_format($affiliate->clicks) }}</td>
                            <td class="end num">{{ number_format($affiliate->referrals_count) }}</td>
                            <td class="end num">{{ money((int) $affiliate->earned, $affiliate->client->currency) }}</td>
                            <td>@if ($affiliate->isActive())<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill>{{ __('Paused') }}</x-pill>@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                {{ $affiliates->links() }}
            @endif
        </section>

        <form method="POST" action="{{ route('admin.affiliates.settings') }}" class="card" style="display:grid;gap:1rem">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Program settings') }}</h2></div>
            <x-checkbox name="enabled" :label="__('Affiliate program is open')" :help="__('Clients can join and share their link.')" :checked="setting('affiliates.enabled')" />
            <x-input name="percent" type="number" step="0.01" min="0" max="100" :label="__('Commission %')" :value="setting('affiliates.percent')" required :help="__('Of what the referred client pays, without tax. You can set a different rate for one affiliate.')" />
            <x-checkbox name="recurring" :label="__('Also pay on renewals')" :help="__('Off: only the first order of each referred client earns a commission.')" :checked="setting('affiliates.recurring')" />
            <x-input name="hold_days" type="number" min="0" max="365" :label="__('Days on hold')" :value="setting('affiliates.hold_days')" required :help="__('Commissions wait this long, so a refund can cancel them.')" />
            <x-input name="cookie_days" type="number" min="1" max="365" :label="__('Days a link is remembered')" :value="setting('affiliates.cookie_days')" required />
            @if ($waiting > 0)
                <p class="help" style="margin:0">{{ __('Commissions available to affiliates now: :amount. Affiliates can move them to their wallet themselves.', ['amount' => money((int) $waiting, setting('billing.currency'))]) }}</p>
            @endif
            <div><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
        </form>
    </div>
</x-layouts.admin>
