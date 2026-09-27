@extends('theme::layouts.app')

@section('title', __('Quotes'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Billing') }}</p>
            <h1 style="margin-top:.3rem">{{ __('Quotes') }}</h1>
        </div>
        <a class="btn" href="{{ route('client.invoices.index') }}"><x-icon name="receipt" />{{ __('Invoices') }}</a>
    </div>

    <section class="card card-flush">
        @if ($quotes->isEmpty())
            <div class="empty"><strong>{{ __('No quotes') }}</strong>{{ __('Need something special? Open a ticket and ask us for a quote.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Quote') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Valid until') }}</th><th class="end">{{ __('Total') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($quotes as $quote)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('client.quotes.show', $quote) }}">{{ $quote->displayNumber() }}</a></td>
                        <td>{{ $quote->subject }}</td>
                        <td style="white-space:nowrap">{{ $quote->valid_until->format('d M Y') }}</td>
                        <td class="end num">{{ money($quote->total, $quote->currency) }}</td>
                        <td><x-status :value="$quote->displayStatus()" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $quotes->links() }}
        @endif
    </section>
@endsection
