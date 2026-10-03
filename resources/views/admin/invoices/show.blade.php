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
            <p><a href="{{ route('admin.clients.show', $invoice->client) }}">{{ $invoice->client->name }}</a> · {{ __('Due :date', ['date' => $invoice->due_at->translatedFormat('d M Y')]) }}</p>
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
            @if ($autoPay && $status === \App\Enums\InvoiceStatus::Unpaid)
                <section class="card" style="display:grid;gap:.7rem">
                    <div class="card-header" style="margin:0"><h2>{{ __('Automatic payment') }}</h2>
                        @if ($invoice->autopay_pending && $invoice->autopay_error)
                            <x-pill tone="info">{{ __('Payment not finished') }}</x-pill>
                        @elseif ($invoice->autopay_error)
                            <x-pill tone="crit">{{ __('Charge failed') }}</x-pill>
                        @elseif ($autoPay['automatic'])
                            <x-pill tone="info">{{ __('Charged on :date', ['date' => $autoPay['date']->translatedFormat('d M Y')]) }}</x-pill>
                        @endif
                    </div>
                    @if ($autoPay['method'])
                        <span><b>{{ $autoPay['method']->label() }}</b>@if ($autoPay['method']->expiry())<span class="muted"> · {{ __('Expires :date', ['date' => $autoPay['method']->expiry()]) }}</span>@endif</span>
                    @endif
                    @if ($invoice->autopay_error)
                        <p class="muted" style="margin:0;font-size:.88rem">{{ $invoice->autopay_error }} @if ($autoPay['retries']){{ __('Tries again on :date.', ['date' => $autoPay['date']->translatedFormat('d M Y')]) }}@endif</p>
                    @elseif (! $autoPay['automatic'])
                        <p class="muted" style="margin:0;font-size:.88rem">{{ __('This invoice is not charged by itself: automatic payments are off for this client, or it is not a renewal.') }}</p>
                    @endif
                    @if ($canManage && $autoPay['usable'])
                        <form method="POST" action="{{ route('admin.invoices.charge', $invoice) }}" data-confirm="{{ $autoPay['repeat']
                            ? __('Send the unclear try to :method again? It is the same payment, so :amount is charged only once.', ['amount' => money($invoice->balance(), $invoice->currency), 'method' => $autoPay['method']->label()])
                            : __('Charge :amount to :method now?', ['amount' => money($invoice->balance(), $invoice->currency), 'method' => $autoPay['method']->label()]) }}">
                            @csrf
                            <button class="btn btn-primary" type="submit"><x-icon name="card" />{{ __('Charge now') }}</button>
                        </form>
                    @endif
                </section>
            @endif

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

            @if ($invoice->creditNotes->isNotEmpty())
                <section class="card">
                    <div class="card-header"><h2>{{ __('Credit notes') }}</h2></div>
                    <ul class="list-plain">
                        @foreach ($invoice->creditNotes as $creditNote)
                            <li class="feed-item">
                                <span><a class="mono" href="{{ route('admin.credit-notes.pdf', $creditNote) }}" target="_blank">{{ $creditNote->displayNumber() }}</a> · <b class="num">{{ money($creditNote->total, $creditNote->currency) }}</b>
                                    <br><span class="faint" style="font-size:.85rem">{{ $creditNote->methodLabel() }}@if ($creditNote->reason) · {{ $creditNote->reason }}@endif</span>
                                </span>
                                <time>{{ $creditNote->issued_at->translatedFormat('d M Y') }}</time>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            @if ($canManage && $status === \App\Enums\InvoiceStatus::Paid && $invoice->creditableAmount() > 0)
                <section class="card">
                    <div class="card-header"><h2>{{ __('Refund') }}</h2></div>
                    <form method="POST" action="{{ route('admin.invoices.refund', $invoice) }}" data-confirm="{{ __('Refund this invoice? This cannot be undone.') }}" style="display:grid;gap:.9rem">
                        @csrf
                        <p class="muted" style="margin:0">{{ __('Refunds :amount, marks the invoice Refunded and makes a credit note for it. Services on the invoice are not changed.', ['amount' => money($invoice->creditableAmount(), $invoice->currency)]) }}</p>
                        @if ($canRefundThroughGateway)
                            <x-checkbox name="through_gateway" :label="__('Send the money back through the payment gateway')" :help="__('Payments made another way are only recorded. Refund those yourself.')" checked />
                        @else
                            <p class="faint" style="margin:0;font-size:.85rem">{{ __('No payment here can be refunded through a gateway. Send the money back yourself.') }}</p>
                        @endif
                        <button class="btn btn-danger" type="submit">{{ __('Refund invoice') }}</button>
                    </form>
                </section>

                <section class="card" x-data="{ method: @js(old('method', $creditToWallet ? 'wallet' : 'refund')) }">
                    <div class="card-header"><h2>{{ __('Issue a credit note') }}</h2></div>
                    <form method="POST" action="{{ route('admin.invoices.credit-notes.store', $invoice) }}" style="display:grid;gap:.9rem">
                        @csrf
                        <p class="muted" style="margin:0">{{ __('Take back part of this invoice, for example for a day of downtime. The invoice stays as it is.') }}</p>
                        <x-input name="amount" type="number" step="0.01" min="0.01" :max="\App\Support\Money::toDecimal($invoice->creditableAmount())" :label="__('Amount (:currency), with tax', ['currency' => $invoice->currency])" :value="\App\Support\Money::toDecimal($invoice->creditableAmount())" required />
                        <div class="field">
                            <label for="f-credit-method">{{ __('What happens to the money') }}</label>
                            <select id="f-credit-method" name="method" class="select" x-model="method">
                                @if ($creditToWallet)
                                    <option value="wallet">{{ __('Add it to the client\'s wallet') }}</option>
                                @endif
                                <option value="refund">{{ __('Send it back to the client') }}</option>
                                <option value="none">{{ __('Nothing, I settle it myself') }}</option>
                            </select>
                        </div>
                        @if ($canRefundThroughGateway)
                            <div x-show="method === 'refund'" x-cloak>
                                <x-checkbox name="through_gateway" id="f-credit-through-gateway" :label="__('Send the money back through the payment gateway')" checked />
                            </div>
                        @endif
                        <x-input name="reason" :label="__('Reason')" maxlength="500" :help="__('Shown on the credit note, for example “Two days of downtime in May”.')" />
                        <button class="btn" type="submit">{{ __('Issue credit note') }}</button>
                    </form>
                </section>
            @endif

            <section class="card">
                <div class="card-header"><h2>{{ __('Payments') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($invoice->transactions as $transaction)
                        <li class="feed-item">
                            <span><b class="num">{{ money($transaction->amount, $transaction->currency) }}</b> · {{ $transaction->gatewayLabel() }}
                                @if ($transaction->type === 'refund')<x-pill>{{ __('Refund') }}</x-pill>@endif
                                @if ($transaction->reference)<br><span class="mono faint">{{ $transaction->reference }}</span>@endif
                            </span>
                            <time>{{ $transaction->paid_at->translatedFormat('d M Y') }}</time>
                        </li>
                    @empty
                        <li class="muted">{{ __('No payments yet.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card">
                <dl class="dl">
                    <dt>{{ __('Issued') }}</dt><dd>{{ $invoice->issued_at->translatedFormat('d M Y') }}</dd>
                    <dt>{{ __('Due') }}</dt><dd>{{ $invoice->due_at->translatedFormat('d M Y') }}</dd>
                    <dt>{{ __('Paid') }}</dt><dd>{{ $invoice->paid_at?->translatedFormat('d M Y H:i') ?? '—' }}</dd>
                    <dt>{{ __('Reminders sent') }}</dt><dd>{{ $invoice->reminder_count }}</dd>
                </dl>
            </section>
        </div>
    </div>
</x-layouts.admin>
