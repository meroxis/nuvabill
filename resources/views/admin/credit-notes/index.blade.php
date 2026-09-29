<x-layouts.admin :title="__('Credit notes')">
    <div class="page-head">
        <div>
            <h1>{{ __('Credit notes') }}</h1>
            <p>{{ __('Refunds and credits on paid invoices. The invoices themselves never change.') }}</p>
        </div>
        <a class="btn" href="{{ route('admin.exports.index', ['type' => 'credit-notes']) }}"><x-icon name="download" />{{ __('Export') }}</a>
    </div>

    @include('admin.invoices.nav')

    <div class="filters">
        <form method="GET" action="{{ route('admin.credit-notes.index') }}" style="margin-inline-start:auto;display:flex;gap:6px">
            <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Number, invoice or client') }}" aria-label="{{ __('Search credit notes') }}" style="width:240px">
        </form>
    </div>

    <section class="card card-flush">
        @if ($creditNotes->isEmpty())
            <div class="empty"><strong>{{ __('No credit notes yet') }}</strong>{{ __('Refund a paid invoice, or open it and choose “Issue a credit note”.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Credit note') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Client') }}</th><th>{{ __('Date') }}</th><th class="end">{{ __('Total') }}</th><th>{{ __('Money') }}</th></tr></thead>
                <tbody>
                @foreach ($creditNotes as $creditNote)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('admin.credit-notes.pdf', $creditNote) }}" target="_blank">{{ $creditNote->displayNumber() }}</a>@if ($creditNote->reason)<div class="faint" style="font-size:.8rem">{{ \Illuminate\Support\Str::limit($creditNote->reason, 60) }}</div>@endif</td>
                        <td><a class="mono" href="{{ route('admin.invoices.show', $creditNote->invoice_id) }}">{{ $creditNote->invoice?->displayNumber() }}</a></td>
                        <td>{{ $creditNote->client?->name }}</td>
                        <td class="num" style="white-space:nowrap">{{ $creditNote->issued_at->translatedFormat('d M Y') }}</td>
                        <td class="end num">{{ money($creditNote->total, $creditNote->currency) }}</td>
                        <td>{{ $creditNote->methodLabel() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
        {{ $creditNotes->links() }}
    </section>
</x-layouts.admin>
