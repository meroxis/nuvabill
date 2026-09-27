@php $admin = auth('admin')->user(); @endphp
<x-layouts.admin :title="__('Quote :number', ['number' => $quote->displayNumber()])">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Quote') }}</p>
            <h1 style="margin-top:.2rem"><span class="mono">{{ $quote->displayNumber() }}</span> <x-status :value="$quote->displayStatus()" style="vertical-align:middle" /></h1>
            <p>{{ $quote->subject }} · <a class="row-link" href="{{ route('admin.clients.show', $quote->client) }}">{{ $quote->client->name }}</a></p>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('admin.quotes.pdf', $quote) }}" target="_blank" rel="noopener"><x-icon name="download" />{{ __('PDF') }}</a>
            @if ($admin->hasPermission('billing.manage') && $quote->isEditable())
                <a class="btn" href="{{ route('admin.quotes.edit', $quote) }}">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('admin.quotes.send', $quote) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit"><x-icon name="mail" />{{ $quote->sent_at ? __('Send again') : __('Send to client') }}</button>
                </form>
            @endif
            @if ($admin->hasPermission('billing.manage') && $quote->status->value === 'draft')
                <form method="POST" action="{{ route('admin.quotes.destroy', $quote) }}" data-confirm="{{ __('Delete this draft?') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-ghost" type="submit" aria-label="{{ __('Delete draft') }}"><x-icon name="trash" /></button>
                </form>
            @endif
        </div>
    </div>

    <div class="grid-2">
        <section class="card card-flush">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Description') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                <tbody>
                    @foreach ($quote->items as $item)
                        <tr>
                            <td>{{ $item->description }}@if ($quote->tax_rate !== null && ! $item->taxed) <x-pill>{{ __('No tax') }}</x-pill>@endif</td>
                            <td class="end num">{{ money($item->amount, $quote->currency) }}</td>
                        </tr>
                    @endforeach
                    <tr><td class="end muted">{{ __('Subtotal') }}</td><td class="end num">{{ money($quote->subtotal, $quote->currency) }}</td></tr>
                    @if ($quote->taxLabel())
                        <tr><td class="end muted">{{ $quote->taxLabel() }}</td><td class="end num">{{ money($quote->tax, $quote->currency) }}</td></tr>
                    @endif
                    <tr><td class="end"><b>{{ __('Total') }}</b></td><td class="end num"><b>{{ money($quote->total, $quote->currency) }}</b></td></tr>
                </tbody>
            </table></div>
        </section>

        <div style="display:grid;gap:14px;align-content:start">
            <section class="card">
                <dl class="dl">
                    <dt>{{ __('Valid until') }}</dt><dd>{{ $quote->valid_until->translatedFormat('d M Y') }}</dd>
                    <dt>{{ __('Sent') }}</dt><dd>{{ $quote->sent_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                    @if ($quote->accepted_at)
                        <dt>{{ __('Accepted') }}</dt><dd>{{ $quote->accepted_at->translatedFormat('d M Y H:i') }}</dd>
                    @endif
                    @if ($quote->declined_at)
                        <dt>{{ __('Declined') }}</dt><dd>{{ $quote->declined_at->translatedFormat('d M Y H:i') }}</dd>
                    @endif
                    @if ($quote->invoice)
                        <dt>{{ __('Invoice') }}</dt><dd><a class="row-link mono" href="{{ route('admin.invoices.show', $quote->invoice) }}">{{ $quote->invoice->displayNumber() }}</a> <x-status :value="$quote->invoice->status" /></dd>
                    @endif
                </dl>
            </section>
            @if ($quote->notes)
                <section class="card"><div class="card-header"><h2>{{ __('Note for the client') }}</h2></div><p class="message-body" style="margin:0">{{ $quote->notes }}</p></section>
            @endif
            @if ($quote->admin_notes)
                <div class="flash" data-tone="warn"><span class="message-body">{{ $quote->admin_notes }}</span></div>
            @endif
        </div>
    </div>
</x-layouts.admin>
