@php $showClient ??= true; @endphp
@if ($invoices->isEmpty())
    <div class="empty">{{ __('No invoices.') }}</div>
@else
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th>{{ __('Invoice') }}</th>
                    @if ($showClient)<th>{{ __('Client') }}</th>@endif
                    <th>{{ __('Due') }}</th>
                    <th class="end">{{ __('Total') }}</th>
                    <th>{{ __('Status') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoices as $invoice)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('admin.invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a></td>
                        @if ($showClient)
                            <td>{{ $invoice->client->name }}</td>
                        @endif
                        <td class="num" style="white-space:nowrap">
                            {{ $invoice->due_at->format('d M Y') }}
                            @if ($invoice->isOverdue())<x-pill tone="crit">{{ __('Overdue') }}</x-pill>@endif
                        </td>
                        <td class="end num">{{ money($invoice->total, $invoice->currency) }}</td>
                        <td><x-status :value="$invoice->status" /></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
