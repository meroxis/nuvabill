@extends('theme::layouts.app')

@section('title', __('Invoice :number', ['number' => $invoice->displayNumber()]))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Invoice') }}</p>
            <h1 style="margin-top:.3rem"><span class="mono">{{ $invoice->displayNumber() }}</span> <x-status :value="$invoice->status" style="vertical-align:middle" /></h1>
            <p>{{ __('Issued :issued · Due :due', ['issued' => $invoice->issued_at->format('d M Y'), 'due' => $invoice->due_at->format('d M Y')]) }}</p>
        </div>
        <a class="btn" href="{{ route('client.invoices.pdf', $invoice) }}"><x-icon name="download" />{{ __('Download PDF') }}</a>
    </div>

    <div class="two-col">
        <section class="card card-flush">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Description') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($invoice->items as $item)
                        <tr><td>{{ $item->description }}</td><td class="end num">{{ money($item->amount, $invoice->currency) }}</td></tr>
                    @endforeach
                    @if ($invoice->tax)
                        <tr><td class="end muted">{{ __('Tax') }}</td><td class="end num">{{ money($invoice->tax, $invoice->currency) }}</td></tr>
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

                @if ($instructions)
                    <div class="flash" data-tone="info" style="display:block">
                        <div class="prose-sm">{!! $instructions !!}</div>
                    </div>
                @endif

                @if ($gateways->isEmpty())
                    <p class="muted" style="margin:0">{{ __('Online payment is not set up yet. Please contact us to pay this invoice.') }}</p>
                @else
                    <form method="POST" action="{{ route('client.invoices.pay', $invoice) }}" style="display:grid;gap:.8rem">
                        @csrf
                        <div class="gateway-list" role="radiogroup" aria-label="{{ __('Payment method') }}">
                            @foreach ($gateways as $slug => $gateway)
                                <label class="gateway-option">
                                    <input type="radio" name="gateway" value="{{ $slug }}" @checked(($invoice->payment_method ?? $gateways->keys()->first()) === $slug) required>
                                    <x-icon :name="$slug === 'banktransfer' ? 'globe' : 'card'" style="width:18px;height:18px" />
                                    {{ $gateway->name() }}
                                </label>
                            @endforeach
                        </div>
                        <button class="btn btn-primary btn-block" type="submit">{{ __('Pay :amount', ['amount' => money($invoice->balance(), $invoice->currency)]) }}</button>
                    </form>
                @endif
            @elseif ($invoice->status === \App\Enums\InvoiceStatus::Paid)
                <div class="flash"><span>{{ __('Paid on :date. Thank you!', ['date' => $invoice->paid_at?->format('d M Y')]) }}</span></div>
            @endif

            @if ($invoice->transactions->isNotEmpty())
                <div style="display:grid;gap:.4rem">
                    <span class="label">{{ __('Payments') }}</span>
                    @foreach ($invoice->transactions as $transaction)
                        <div class="summary-row"><span>{{ $transaction->paid_at->format('d M Y') }}</span><span class="num">{{ money($transaction->amount, $transaction->currency) }}</span></div>
                    @endforeach
                </div>
            @endif
        </aside>
    </div>
@endsection
