<x-layouts.admin :title="__('Invoice :number', ['number' => $invoice->displayNumber()])">
    @php
        $admin = auth('admin')->user();
        $canManage = $admin->hasPermission('billing.manage');
        $status = $invoice->status;
    @endphp

    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Invoice') }}</p>
            <h1 style="margin-top:.2rem"><span class="mono" style="font-size:1.4rem">{{ $invoice->displayNumber() }}</span> <x-status :value="$status" style="vertical-align:middle" />
                @if ($invoice->isOverdue())<x-pill tone="crit" style="vertical-align:middle">{{ __('Overdue') }}</x-pill>@endif
            </h1>
            <p><a href="{{ route('admin.clients.show', $invoice->client) }}">{{ $invoice->client->name }}</a> · {{ __('Due :date', ['date' => $invoice->due_at->format('d M Y')]) }}</p>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('admin.invoices.pdf', $invoice) }}" target="_blank"><x-icon name="download" />{{ __('PDF') }}</a>
            @if ($canManage && $status === \App\Enums\InvoiceStatus::Unpaid)
                <form method="POST" action="{{ route('admin.invoices.email', $invoice) }}">@csrf<button class="btn" type="submit"><x-icon name="mail" />{{ __('Email client') }}</button></form>
            @endif
            @if ($canManage && $status === \App\Enums\InvoiceStatus::Draft)
                <form method="POST" action="{{ route('admin.invoices.publish', $invoice) }}">@csrf<button class="btn btn-primary" type="submit">{{ __('Publish') }}</button></form>
            @endif
            @if ($canManage && in_array($status, [\App\Enums\InvoiceStatus::Unpaid, \App\Enums\InvoiceStatus::Draft], true))
                <form method="POST" action="{{ route('admin.invoices.cancel', $invoice) }}" data-confirm="{{ __('Cancel this invoice? The client will no longer see it as due.') }}">@csrf<button class="btn btn-danger" type="submit">{{ __('Cancel invoice') }}</button></form>
            @endif
        </div>
    </div>

    <div class="grid-2">
        <section class="card card-flush">
            <div class="table-wrap">
                <table class="table">
                    <thead><tr><th>{{ __('Description') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                    <tbody>
                        @foreach ($invoice->items as $item)
                            <tr>
                                <td>
                                    {{ $item->description }}
                                    @if ($item->service && $admin->hasPermission('services.manage'))
                                        <a href="{{ route('admin.services.show', $item->service) }}" class="faint" style="font-size:.8rem">· {{ __('service #:id', ['id' => $item->service_id]) }}</a>
                                    @endif
                                </td>
                                <td class="end num">{{ money($item->amount, $invoice->currency) }}</td>
                            </tr>
                        @endforeach
                        <tr><td class="end muted">{{ __('Subtotal') }}</td><td class="end num">{{ money($invoice->subtotal, $invoice->currency) }}</td></tr>
                        @if ($invoice->tax)
                            <tr><td class="end muted">{{ $invoice->taxLabel() }}</td><td class="end num">{{ money($invoice->tax, $invoice->currency) }}</td></tr>
                        @endif
                        <tr><td class="end"><b>{{ __('Total') }}</b></td><td class="end num"><b>{{ money($invoice->total, $invoice->currency) }}</b></td></tr>
                        <tr><td class="end muted">{{ __('Paid') }}</td><td class="end num">{{ money($invoice->amount_paid, $invoice->currency) }}</td></tr>
                        <tr><td class="end"><b>{{ __('Balance') }}</b></td><td class="end num"><b>{{ money($invoice->balance(), $invoice->currency) }}</b></td></tr>
                    </tbody>
                </table>
            </div>
            @if ($invoice->notes)
                <div style="padding:1rem 1.1rem;border-top:1px solid var(--nb-line)" class="message-body muted">{{ $invoice->notes }}</div>
            @endif
        </section>

        <div style="display:grid;gap:14px;align-content:start">
            @if ($canManage && $status === \App\Enums\InvoiceStatus::Unpaid)
                <section class="card">
                    <div class="card-header"><h2>{{ __('Record a payment') }}</h2></div>
                    <form method="POST" action="{{ route('admin.invoices.payments.store', $invoice) }}" style="display:grid;gap:.9rem">
                        @csrf
                        <x-input name="amount" type="number" step="0.01" min="0.01" :label="__('Amount (:currency)', ['currency' => $invoice->currency])" :value="\App\Support\Money::toDecimal($invoice->balance())" required />
                        <x-select name="method" :label="__('Paid with')" :options="$methods" value="banktransfer" required />
                        <x-input name="reference" :label="__('Reference')" :help="__('Bank or gateway transaction ID. Optional.')" />
                        <x-input name="paid_at" type="date" :label="__('Date received')" :value="today()->toDateString()" required />
                        <button class="btn btn-primary" type="submit">{{ __('Record payment') }}</button>
                    </form>
                </section>
            @endif

            @if ($canManage && $status === \App\Enums\InvoiceStatus::Paid)
                <section class="card">
                    <div class="card-header"><h2>{{ __('Refund') }}</h2></div>
                    <form method="POST" action="{{ route('admin.invoices.refund', $invoice) }}" data-confirm="{{ __('Refund this invoice? This cannot be undone.') }}" style="display:grid;gap:.9rem">
                        @csrf
                        <p class="muted" style="margin:0">{{ __('Marks the invoice Refunded and records a refund for each payment. Services on the invoice are not changed.') }}</p>
                        @if ($canRefundThroughGateway)
                            <x-checkbox name="through_gateway" :label="__('Send the money back through the payment gateway')" :help="__('Payments made another way are only recorded. Refund those yourself.')" checked />
                        @else
                            <p class="faint" style="margin:0;font-size:.85rem">{{ __('No payment here can be refunded through a gateway. Send the money back yourself.') }}</p>
                        @endif
                        <button class="btn btn-danger" type="submit">{{ __('Refund invoice') }}</button>
                    </form>
                </section>
            @endif

            <section class="card">
                <div class="card-header"><h2>{{ __('Payments') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($invoice->transactions as $transaction)
                        <li class="feed-item">
                            <span><b class="num">{{ money($transaction->amount, $transaction->currency) }}</b> · {{ $transaction->gateway }}
                                @if ($transaction->type === 'refund')<x-pill>{{ __('Refund') }}</x-pill>@endif
                                @if ($transaction->reference)<br><span class="mono faint">{{ $transaction->reference }}</span>@endif
                            </span>
                            <time>{{ $transaction->paid_at->format('d M Y') }}</time>
                        </li>
                    @empty
                        <li class="muted">{{ __('No payments yet.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card">
                <dl class="dl">
                    <dt>{{ __('Issued') }}</dt><dd>{{ $invoice->issued_at->format('d M Y') }}</dd>
                    <dt>{{ __('Due') }}</dt><dd>{{ $invoice->due_at->format('d M Y') }}</dd>
                    <dt>{{ __('Paid') }}</dt><dd>{{ $invoice->paid_at?->format('d M Y H:i') ?? '—' }}</dd>
                    <dt>{{ __('Reminders sent') }}</dt><dd>{{ $invoice->reminder_count }}</dd>
                </dl>
            </section>
        </div>
    </div>
</x-layouts.admin>
