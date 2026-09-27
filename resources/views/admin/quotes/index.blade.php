<x-layouts.admin :title="__('Quotes')">
    <div class="page-head">
        <div>
            <h1>{{ __('Quotes') }}</h1>
            <p>{{ __('Send a price offer. When the client accepts it, an invoice is created.') }}</p>
        </div>
        @if (auth('admin')->user()->hasPermission('billing.manage'))
            <a class="btn btn-primary" href="{{ route('admin.quotes.create') }}"><x-icon name="plus" />{{ __('New quote') }}</a>
        @endif
    </div>

    <div class="filters">
        @foreach (['' => __('All'), 'draft' => __('Drafts'), 'sent' => __('Sent'), 'accepted' => __('Accepted'), 'declined' => __('Declined')] as $key => $label)
            <a class="chip" href="{{ route('admin.quotes.index', $key === '' ? [] : ['status' => $key]) }}" @if ((string) $status === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
    </div>

    <section class="card card-flush">
        @if ($quotes->isEmpty())
            <div class="empty"><strong>{{ __('No quotes yet') }}</strong>{{ __('Quotes are for custom work, like a dedicated server or a migration.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Quote') }}</th><th>{{ __('Client') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Valid until') }}</th><th class="end">{{ __('Total') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($quotes as $quote)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('admin.quotes.show', $quote) }}">{{ $quote->displayNumber() }}</a></td>
                        <td>{{ $quote->client->name }}</td>
                        <td>{{ $quote->subject }}</td>
                        <td class="num" style="white-space:nowrap">{{ $quote->valid_until->format('d M Y') }}</td>
                        <td class="end num">{{ money($quote->total, $quote->currency) }}</td>
                        <td><x-status :value="$quote->displayStatus()" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $quotes->links() }}
        @endif
    </section>
</x-layouts.admin>
