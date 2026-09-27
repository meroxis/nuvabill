@extends('theme::layouts.app')

@section('title', __('Affiliate program'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Affiliate program') }}</p>
            <h1 style="margin-top:.3rem">{{ __('Earn :percent% for every client you send us', ['percent' => rtrim(rtrim(number_format($affiliate?->rate() ?? (float) setting('affiliates.percent'), 2, '.', ''), '0'), '.')]) }}</h1>
            <p>{{ setting('affiliates.recurring') ? __('You earn on every payment your referrals make, including renewals.') : __('You earn on the first order of each client you refer.') }}</p>
        </div>
    </div>

    @if (! $affiliate)
        <section class="card" style="display:grid;gap:1rem;max-width:640px">
            <ol class="list-plain" style="display:grid;gap:.6rem;margin:0">
                <li><b>1.</b> {{ __('Join and get your personal link.') }}</li>
                <li><b>2.</b> {{ __('Share it on your website, social media or with friends.') }}</li>
                <li><b>3.</b> {{ __('When someone signs up through it and pays, you earn a commission. After :days days it is yours to use.', ['days' => (int) setting('affiliates.hold_days')]) }}</li>
            </ol>
            <form method="POST" action="{{ route('client.affiliate.join') }}">
                @csrf
                <button class="btn btn-primary" type="submit"><x-icon name="star" />{{ __('Join the affiliate program') }}</button>
            </form>
        </section>
    @else
        @unless ($affiliate->isActive())
            <div class="flash" data-tone="warn"><span>{{ __('Your affiliate account is paused. Contact support for help.') }}</span></div>
        @endunless

        <section class="card" style="display:grid;gap:.6rem" x-data="{ copied: false }">
            <span class="muted">{{ __('Your link') }}</span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
                <input class="input mono" type="text" value="{{ $affiliate->link() }}" readonly style="flex:1;min-width:220px" aria-label="{{ __('Your link') }}" @focus="$event.target.select()">
                <button class="btn" type="button" @click="navigator.clipboard.writeText(@js($affiliate->link())); copied = true; setTimeout(() => copied = false, 2000)"><x-icon name="check" x-show="copied" x-cloak /><span x-text="copied ? @js(__('Copied')) : @js(__('Copy link'))"></span></button>
            </div>
            <span class="faint" style="font-size:.85rem">{{ __('Any page works: add ?ref=:code to the end of a link to our store.', ['code' => $affiliate->code]) }}</span>
        </section>

        <div class="stats-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin-top:14px">
            <div class="card"><span class="muted">{{ __('Visits') }}</span><b class="num" style="display:block;font-size:1.5rem">{{ number_format($affiliate->clicks) }}</b></div>
            <div class="card"><span class="muted">{{ __('Sign-ups') }}</span><b class="num" style="display:block;font-size:1.5rem">{{ number_format($referrals) }}</b></div>
            <div class="card"><span class="muted">{{ __('On hold') }}</span><b class="num" style="display:block;font-size:1.5rem">{{ money($totals['pending'], $client->currency) }}</b></div>
            <div class="card"><span class="muted">{{ __('Available') }}</span><b class="num" style="display:block;font-size:1.5rem">{{ money($totals['available'], $client->currency) }}</b></div>
            <div class="card"><span class="muted">{{ __('Paid to you') }}</span><b class="num" style="display:block;font-size:1.5rem">{{ money($totals['paid'], $client->currency) }}</b></div>
        </div>

        @if ($totals['available'] > 0 && $affiliate->isActive())
            <form method="POST" action="{{ route('client.affiliate.withdraw') }}" class="card" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:14px">
                @csrf
                <span>{{ __('Move :amount to your wallet and use it to pay your invoices.', ['amount' => money($totals['available'], $client->currency)]) }}</span>
                <button class="btn btn-primary" type="submit"><x-icon name="card" />{{ __('Move to my wallet') }}</button>
            </form>
        @endif

        <section class="card card-flush" style="margin-top:14px">
            <div class="card-header" style="padding:1rem 1.1rem 0"><h2>{{ __('Commissions') }}</h2></div>
            @if ($commissions->isEmpty())
                <div class="empty"><strong>{{ __('No commissions yet') }}</strong>{{ __('Share your link to start earning.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                    <tbody>
                    @foreach ($commissions as $commission)
                        <tr>
                            <td style="white-space:nowrap">{{ $commission->created_at->format('d M Y') }}</td>
                            <td><span class="pill" data-tone="{{ $commission->tone() }}">{{ $commission->statusLabel() }}</span></td>
                            <td class="end num">{{ money($commission->amount, $commission->currency) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                {{ $commissions->links() }}
            @endif
        </section>
    @endif
@endsection
