@extends('theme::layouts.app')

@section('title', __('Invoices'))

@section('content')
    <div class="page-title">
        <div><h1>{{ __('Invoices') }}</h1></div>
        <div class="form-actions" style="margin:0">
        @if (auth('web')->user()->quotes()->where('status', '!=', 'draft')->exists())
            <a class="btn" href="{{ route('client.quotes.index') }}"><x-icon name="layers" />{{ __('Quotes') }}</a>
        @endif
        @if (setting('wallet.enabled') || auth('web')->user()->credit > 0)
            <a class="btn" href="{{ route('client.wallet') }}"><x-icon name="card" />{{ __('Wallet: :amount', ['amount' => money(auth('web')->user()->credit, auth('web')->user()->currency)]) }}</a>
        @endif
        </div>
    </div>

    <section class="card card-flush">
        @if ($invoices->isEmpty())
            <div class="empty"><strong>{{ __('No invoices yet') }}</strong></div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Date') }}</th><th>{{ __('Due') }}</th><th class="end">{{ __('Total') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('client.invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a></td>
                        <td style="white-space:nowrap">{{ $invoice->issued_at->format('d M Y') }}</td>
                        <td style="white-space:nowrap">{{ $invoice->due_at->format('d M Y') }}</td>
                        <td class="end num">{{ money($invoice->total, $invoice->currency) }}</td>
                        <td>
                            <x-status :value="$invoice->status" />
                            @if ($invoice->isPayable())<a class="btn btn-sm btn-primary" href="{{ route('client.invoices.show', $invoice) }}" style="margin-inline-start:.4rem">{{ __('Pay') }}</a>@endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $invoices->links() }}
        @endif
    </section>
@endsection
