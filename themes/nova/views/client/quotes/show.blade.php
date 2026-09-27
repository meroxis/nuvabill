@extends('theme::layouts.app')

@section('title', __('Quote :number', ['number' => $quote->displayNumber()]))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Quote') }}</p>
            <h1 style="margin-top:.3rem"><span class="mono">{{ $quote->displayNumber() }}</span> <x-status :value="$quote->displayStatus()" style="vertical-align:middle" /></h1>
            <p>{{ $quote->subject }}</p>
        </div>
        <a class="btn" href="{{ route('client.quotes.pdf', $quote) }}"><x-icon name="download" />{{ __('Download PDF') }}</a>
    </div>

    <div class="two-col">
        <section class="card card-flush">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Description') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($quote->items as $item)
                        <tr><td><bdi>{{ $item->description }}</bdi></td><td class="end num">{{ money($item->amount, $quote->currency) }}</td></tr>
                    @endforeach
                    @if ($quote->tax > 0)
                        <tr><td class="end muted">{{ $quote->taxLabel() }}</td><td class="end num">{{ money($quote->tax, $quote->currency) }}</td></tr>
                    @endif
                    <tr><td class="end"><b>{{ __('Total') }}</b></td><td class="end num"><b>{{ money($quote->total, $quote->currency) }}</b></td></tr>
                </tbody>
            </table></div>
            @if ($quote->notes)
                <div class="message-body muted" style="padding:1rem 1.1rem;border-top:1px solid var(--nb-line)">{{ $quote->notes }}</div>
            @endif
        </section>

        <aside class="card" style="display:grid;gap:1rem;align-content:start">
            <div class="summary-row total" style="border:0;padding:0"><span>{{ __('Total') }}</span><span class="num">{{ money($quote->total, $quote->currency) }}</span></div>

            @if ($quote->canBeAccepted())
                <p class="muted" style="margin:0">{{ __('Valid until :date. When you accept, we create an invoice for you to pay.', ['date' => $quote->valid_until->translatedFormat('d M Y')]) }}</p>
                <form method="POST" action="{{ route('client.quotes.accept', $quote) }}">
                    @csrf
                    <button class="btn btn-primary btn-block" type="submit"><x-icon name="check" />{{ __('Accept quote') }}</button>
                </form>
                <form method="POST" action="{{ route('client.quotes.decline', $quote) }}" data-confirm="{{ __('Decline this quote?') }}">
                    @csrf
                    <button class="btn btn-block btn-ghost" type="submit">{{ __('Decline') }}</button>
                </form>
            @elseif ($quote->displayStatus()->value === 'expired')
                <p class="muted" style="margin:0">{{ __('This quote expired on :date. Open a ticket and we will send you a new one.', ['date' => $quote->valid_until->translatedFormat('d M Y')]) }}</p>
            @elseif ($quote->invoice)
                <p class="muted" style="margin:0">{{ __('You accepted this quote on :date.', ['date' => $quote->accepted_at->translatedFormat('d M Y')]) }}</p>
                <a class="btn btn-primary btn-block" href="{{ route('client.invoices.show', $quote->invoice) }}">{{ $quote->invoice->isPayable() ? __('Pay the invoice') : __('View the invoice') }}</a>
            @elseif ($quote->declined_at)
                <p class="muted" style="margin:0">{{ __('You declined this quote on :date.', ['date' => $quote->declined_at->translatedFormat('d M Y')]) }}</p>
            @endif
        </aside>
    </div>
@endsection
