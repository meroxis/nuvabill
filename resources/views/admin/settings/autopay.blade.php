<x-layouts.admin :title="__('Automatic payments')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <div class="grid-halves" style="align-items:start">
        <form method="POST" action="{{ route('admin.settings.autopay.update') }}" class="card" style="display:grid;gap:1rem;min-width:0">
            @csrf
            @method('PUT')
            <div>
                <h2 style="font-size:1.05rem">{{ __('Automatic payments') }}</h2>
                <p class="muted" style="margin:.3rem 0 0;font-size:.9rem">{{ __('Renewal invoices are paid from the wallet first, then charged to the card or PayPal account the client saved. Clients save one themselves when they pay, or in Account → Payment methods.') }}</p>
            </div>
            <x-checkbox name="autopay" :label="__('Charge saved cards and PayPal accounts for renewals')" :checked="setting('billing.autopay')" />
            <div class="form-grid">
                <x-input name="days_before" type="number" min="0" max="14" :label="__('Charge this many days before the due date')" :value="setting('billing.autopay_days_before')" :help="__('0 charges on the due date.')" required />
                <x-input name="notice_days" type="number" min="0" max="14" :label="__('Email clients this many days before a charge')" :value="setting('billing.autopay_notice_days')" :help="__('Says the amount and which card or PayPal account pays. 0 sends no email.')" required />
            </div>
            <x-input name="retry_days" :label="__('If a charge fails, try again after (days)')" :value="$retryDays" placeholder="3, 7"
                :help="__('Counted from the first try. Each time the client gets an email, and a Telegram or WhatsApp message if they linked one, with a Pay button. After the last try your usual overdue reminders and suspension rules take over.')" />
            <x-checkbox name="card_notice" :label="__('Remind clients 30 days before a saved card expires')" :checked="setting('billing.autopay_card_notice')" />
            <x-checkbox name="offer_save" :label="__('Offer “Save it” when clients pay an invoice')" :checked="setting('billing.autopay_offer_save')" :help="__('Clients choose it themselves; it is never ticked for them.')" />
            <div><button class="btn btn-primary" type="submit">{{ __('Save settings') }}</button></div>
        </form>

        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Payment methods') }}</h2></div>
                @forelse ($gateways as $slug => $gateway)
                    <div class="attention" data-tone="{{ in_array($slug, $savable, true) ? 'good' : 'info' }}">
                        <span style="flex:1">{{ $gateway->name() }}</span>
                        @if (in_array($slug, $savable, true))
                            <x-pill tone="good">{{ __('Can charge automatically') }}</x-pill>
                        @else
                            <x-pill>{{ __('Pay by hand') }}</x-pill>
                        @endif
                    </div>
                @empty
                    <div class="attention" data-tone="info"><span>{{ __('No payment gateway is set up yet.') }}</span></div>
                @endforelse
            </section>

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Tomorrow') }}</h2><span class="pill">{{ trans_choice(':count renewal|:count renewals', $tomorrow->count(), ['count' => $tomorrow->count()]) }}</span></div>
                @forelse ($tomorrow as $row)
                    <div class="attention" data-tone="info">
                        <span style="flex:1;min-width:0"><a class="row-link" href="{{ route('admin.invoices.show', $row['invoice']) }}">{{ $row['invoice']->displayNumber() }}</a> · {{ $row['invoice']->client?->name }}<span class="muted" style="display:block;font-size:.82rem">{{ $row['method']->label() }}</span></span>
                        <b class="num">{{ money($row['invoice']->balance(), $row['invoice']->currency) }}</b>
                    </div>
                @empty
                    <div class="attention" data-tone="good"><span>{{ __('Nothing will be charged tomorrow.') }}</span></div>
                @endforelse
            </section>

            <section class="card" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px">
                <div><b class="num" style="font-size:1.4rem;display:block">{{ $paid }}</b><span class="muted" style="font-size:.82rem">{{ __('paid automatically in the last 30 days') }}</span></div>
                <div><b class="num" style="font-size:1.4rem;display:block">{{ $failed }}</b><span class="muted" style="font-size:.82rem">{{ __('charges that failed in the last 30 days') }}</span></div>
                <div><b class="num" style="font-size:1.4rem;display:block">{{ $savedCount }}</b><span class="muted" style="font-size:.82rem">{{ __('saved cards and PayPal accounts') }}</span></div>
            </section>
        </div>
    </div>
</x-layouts.admin>
