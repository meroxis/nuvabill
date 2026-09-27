@extends('theme::layouts.app')

@section('title', __('Wallet'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Billing') }}</p>
            <h1 style="margin-top:.3rem">{{ __('Wallet') }}</h1>
            <p>{{ __('Keep money on your account and pay invoices with one click.') }}</p>
        </div>
        <a class="btn" href="{{ route('client.invoices.index') }}"><x-icon name="receipt" />{{ __('Invoices') }}</a>
    </div>

    <div class="two-col">
        <section class="card card-flush">
            <div class="card-header" style="padding:1rem 1.1rem 0"><h2>{{ __('History') }}</h2></div>
            @if ($entries->isEmpty())
                <div class="empty"><strong>{{ __('No wallet activity yet') }}</strong>{{ __('Money you add, overpayments and refunds show here.') }}</div>
            @else
                <div class="table-wrap"><table class="table">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Description') }}</th><th class="end">{{ __('Amount') }}</th><th class="end">{{ __('Balance') }}</th></tr></thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td style="white-space:nowrap">{{ $entry->created_at->format('d M Y') }}</td>
                            <td>
                                {{ $entry->description }}
                                @if ($entry->invoice_id)<br><a class="muted mono" style="font-size:.82rem" href="{{ route('client.invoices.show', $entry->invoice_id) }}">{{ $entry->invoice?->displayNumber() }}</a>@endif
                            </td>
                            <td class="end num" style="color:{{ $entry->amount >= 0 ? 'var(--nb-good)' : 'inherit' }}">{{ $entry->amount >= 0 ? '+' : '' }}{{ money($entry->amount, $entry->currency) }}</td>
                            <td class="end num">{{ money($entry->balance, $entry->currency) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                {{ $entries->links() }}
            @endif
        </section>

        <aside style="display:grid;gap:14px;align-content:start">
            <section class="card" style="display:grid;gap:.4rem">
                <span class="muted">{{ __('Balance') }}</span>
                <b class="num" style="font-size:2rem;line-height:1.1">{{ money($client->credit, $client->currency) }}</b>
            </section>

            @if ($canAdd)
                <form method="POST" action="{{ route('client.wallet.store') }}" class="card" style="display:grid;gap:.8rem">
                    @csrf
                    <h2 style="font-size:1.05rem">{{ __('Add funds') }}</h2>
                    <x-input name="amount" type="number" step="0.01" :min="$min / 100" :max="$max / 100" :label="__('Amount (:currency)', ['currency' => $client->currency])" required :help="__('Between :min and :max. You pay an invoice for it, then the money is in your wallet.', ['min' => money($min, $client->currency), 'max' => money($max, $client->currency)])" />
                    <button class="btn btn-primary btn-block" type="submit"><x-icon name="plus" />{{ __('Continue to payment') }}</button>
                </form>
            @endif

            @if ($client->credit > 0 && $unpaid->isNotEmpty())
                <section class="card" style="display:grid;gap:.6rem">
                    <h2 style="font-size:1.05rem">{{ __('Pay with your wallet') }}</h2>
                    @foreach ($unpaid as $invoice)
                        <form method="POST" action="{{ route('client.invoices.wallet', $invoice) }}" class="summary-row" style="align-items:center">
                            @csrf
                            <span class="mono">{{ $invoice->displayNumber() }} · {{ money($invoice->balance(), $invoice->currency) }}</span>
                            <button class="btn btn-sm btn-primary" type="submit">{{ __('Pay') }}</button>
                        </form>
                    @endforeach
                </section>
            @endif
        </aside>
    </div>
@endsection
