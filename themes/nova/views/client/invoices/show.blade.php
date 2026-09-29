@extends('theme::layouts.app')

@section('title', __('Invoice :number', ['number' => $invoice->displayNumber()]))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Invoice') }}</p>
            <h1 style="margin-top:.3rem"><span class="mono">{{ $invoice->displayNumber() }}</span> <x-status :value="$invoice->status" style="vertical-align:middle" /></h1>
            <p>{{ __('Issued :issued · Due :due', ['issued' => $invoice->issued_at->translatedFormat('d M Y'), 'due' => $invoice->due_at->translatedFormat('d M Y')]) }}</p>
        </div>
        <a class="btn" href="{{ route('client.invoices.pdf', $invoice) }}"><x-icon name="download" />{{ __('Download PDF') }}</a>
    </div>

    <div class="two-col">
        <section class="card card-flush">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Description') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr><td><bdi>{{ $item->description }}</bdi></td><td class="end num">{{ money($item->amount, $invoice->currency) }}</td></tr>
                    @endforeach
                    @if ($invoice->tax)
                        <tr><td class="end muted">{{ $invoice->taxLabel() }}</td><td class="end num">{{ money($invoice->tax, $invoice->currency) }}</td></tr>
                    @endif
                    <tr><td class="end"><b>{{ __('Total') }}</b></td><td class="end num"><b>{{ money($invoice->total, $invoice->currency) }}</b></td></tr>
                    @if ($invoice->amount_paid > 0)
                        <tr><td class="end muted">{{ __('Paid') }}</td><td class="end num">{{ money($invoice->amount_paid, $invoice->currency) }}</td></tr>
                    @endif
                </tbody>
            </table></div>
            @if ($invoice->notes)
                <div class="message-body muted" style="padding:1rem 1.1rem;border-top:1px solid var(--nb-line)">{{ $invoice->notes }}</div>
            @endif
        </section>

        <aside class="card" style="display:grid;gap:1rem">
            @if ($invoice->isPayable())
                <div class="summary-row total" style="border:0;padding:0"><span>{{ __('Amount due') }}</span><span class="num">{{ money($invoice->balance(), $invoice->currency) }}</span></div>

                @if (! empty($autoPay))
                    <div class="flash" data-tone="{{ $autoPay['failed'] ? 'warn' : 'info' }}" style="margin:0">
                        <span>
                            @if ($autoPay['failed'] && $autoPay['retries'])
                                {{ __('We could not charge :method. We try again on :date, or you can pay now.', ['method' => $autoPay['method']->label(), 'date' => $autoPay['date']->translatedFormat('d M Y')]) }}
                            @elseif ($autoPay['failed'])
                                {{ __('We could not charge :method. Please pay this invoice here.', ['method' => $autoPay['method']->label()]) }}
                            @elseif ($autoPay['wallet'])
                                {{ __('This invoice is paid automatically from your wallet credit on :date. You can also pay it now.', ['date' => $autoPay['date']->translatedFormat('d M Y')]) }}
                            @else
                                {{ __('This invoice is paid automatically with :method on :date. You can also pay it now.', ['method' => $autoPay['method']->label(), 'date' => $autoPay['date']->translatedFormat('d M Y')]) }}
                            @endif
                            <a href="{{ route('client.account.payment-methods') }}">{{ __('Payment methods') }}</a>
                        </span>
                    </div>
                @endif

                @php $walletCredit = (int) auth('web')->user()->credit; @endphp
                @if ($walletCredit > 0 && auth('web')->user()->currency === $invoice->currency && ! app(\App\Billing\Wallet::class)->isTopUp($invoice))
                    <form method="POST" action="{{ route('client.invoices.wallet', $invoice) }}" style="display:grid;gap:.35rem">
                        @csrf
                        <button class="btn btn-block" type="submit"><x-icon name="card" />{{ __('Pay :amount from my wallet', ['amount' => money(min($walletCredit, $invoice->balance()), $invoice->currency)]) }}</button>
                        <span class="muted" style="font-size:.8rem;text-align:center">{{ __('Wallet balance: :amount', ['amount' => money($walletCredit, $invoice->currency)]) }}</span>
                    </form>
                @endif

                @if ($instructions)
                    <div class="flash" data-tone="info" style="display:block">
                        <div class="prose-sm">{!! $instructions !!}</div>
                    </div>
                @endif

                @if ($qr)
                    <div class="qr-pay" x-data="{ paid: false }" x-init="setInterval(() => { if (paid) return; fetch(@js(route('client.invoices.payment-status', $invoice)), { headers: { Accept: 'application/json' } }).then(r => r.ok ? r.json() : {}).then(d => { if (d.paid) { paid = true; window.location.reload(); } }).catch(() => {}); }, 5000)">
                        <strong>{{ __('Pay with :gateway', ['gateway' => $qr['gateway']]) }}</strong>
                        <img src="{{ $qr['image'] }}" alt="{{ __('QR code to pay invoice :number', ['number' => $invoice->displayNumber()]) }}" width="220" height="220">
                        <span class="muted">{{ __('Scan the code with your banking app, or enter this code:') }}</span>
                        @if ($qr['code'])<code class="mono qr-code">{{ $qr['code'] }}</code>@endif
                        @if ($qr['links'])
                            <div style="display:flex;gap:6px;flex-wrap:wrap;justify-content:center">
                                @foreach ($qr['links'] as $label => $url)
                                    <a class="btn btn-sm" href="{{ $url }}" rel="noopener">{{ $label }}</a>
                                @endforeach
                            </div>
                        @endif
                        @if ($qr['expires_at'])
                            <span class="faint" style="font-size:.8rem">{{ __('The code works until :time.', ['time' => \Illuminate\Support\Carbon::parse($qr['expires_at'])->timezone(config('app.timezone'))->format('H:i')]) }}</span>
                        @endif
                        <span class="faint" style="font-size:.8rem" x-text="paid ? @js(__('Payment received!')) : @js(__('This page updates by itself when the payment arrives.'))"></span>
                    </div>
                @endif

                @if ($gateways->isEmpty())
                    <p class="muted" style="margin:0">{{ __('Online payment is not set up yet. Please contact us to pay this invoice.') }}</p>
                @else
                    <form method="POST" action="{{ route('client.invoices.pay', $invoice) }}" style="display:grid;gap:.8rem" x-data="{ gateway: @js($invoice->payment_method ?? $gateways->keys()->first()), savable: @js($savable ?? []) }">
                        @csrf
                        <div class="gateway-list" role="radiogroup" aria-label="{{ __('Payment method') }}">
                            @foreach ($gateways as $slug => $gateway)
                                @php $quote = $gateway instanceof \App\Extensions\Gateways\Gateway ? $gateway->quote($invoice) : null; @endphp
                                <label class="gateway-option">
                                    <input type="radio" name="gateway" value="{{ $slug }}" x-model="gateway" @checked(($invoice->payment_method ?? $gateways->keys()->first()) === $slug) required>
                                    <x-icon :name="$slug === 'banktransfer' ? 'globe' : 'card'" style="width:18px;height:18px" />
                                    {{ $gateway->name() }}
                                    @if ($quote && $quote['currency'] !== $invoice->currency)
                                        <span class="muted" style="font-size:.8rem;margin-inline-start:auto">{{ __('You pay :amount', ['amount' => money((int) ceil($quote['amount'] / 100) * 100, $quote['currency'])]) }}</span>
                                    @endif
                                </label>
                            @endforeach
                        </div>
                        @if (! empty($savable))
                            <label class="check" for="save-method" x-show="savable.includes(gateway)" x-cloak>
                                <input id="save-method" type="checkbox" name="save_method" value="1" :disabled="! savable.includes(gateway)">
                                <span>{{ __('Save it and pay my renewals automatically') }}<br><span class="help">{{ __('We email you before each payment. Turn it off or remove it any time in Payment methods.') }}</span></span>
                            </label>
                        @endif
                        <button class="btn btn-primary btn-block" type="submit">{{ __('Pay :amount', ['amount' => money($invoice->balance(), $invoice->currency)]) }}</button>
                    </form>
                @endif
            @elseif ($invoice->status === \App\Enums\InvoiceStatus::Paid)
                <div class="flash"><span>{{ __('Paid on :date. Thank you!', ['date' => $invoice->paid_at?->translatedFormat('d M Y')]) }}</span></div>
            @elseif ($invoice->status === \App\Enums\InvoiceStatus::Refunded)
                <div class="flash"><span>{{ __('This invoice was refunded.') }}</span></div>
            @endif

            @if ($invoice->transactions->isNotEmpty())
                <div style="display:grid;gap:.4rem">
                    <span class="label">{{ __('Payments') }}</span>
                    @foreach ($invoice->transactions as $transaction)
                        <div class="summary-row"><span>{{ $transaction->paid_at->translatedFormat('d M Y') }}@if ($transaction->type === 'refund') · {{ __('Refund') }}@endif</span><span class="num">{{ money($transaction->amount, $transaction->currency) }}</span></div>
                    @endforeach
                </div>
            @endif

            @if ($invoice->creditNotes->isNotEmpty())
                <div style="display:grid;gap:.4rem">
                    <span class="label">{{ __('Credit notes') }}</span>
                    @foreach ($invoice->creditNotes as $creditNote)
                        <div class="summary-row">
                            <span><a href="{{ route('client.credit-notes.pdf', $creditNote) }}">{{ $creditNote->displayNumber() }}</a> · {{ $creditNote->issued_at->translatedFormat('d M Y') }}</span>
                            <span class="num">{{ money($creditNote->total, $creditNote->currency) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </aside>
    </div>
@endsection
