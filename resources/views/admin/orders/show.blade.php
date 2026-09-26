<x-layouts.admin :title="__('Order #:number', ['number' => $order->number])">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Order') }}</p>
            <h1 style="margin-top:.2rem"><span class="mono">#{{ $order->number }}</span> <x-status :value="$order->status" style="vertical-align:middle" /></h1>
            <p><a href="{{ route('admin.clients.show', $order->client) }}">{{ $order->client->name }}</a> · {{ $order->created_at->format('d M Y H:i') }} · {{ __('IP :ip', ['ip' => $order->ip_address ?? '—']) }}</p>
        </div>
        @if ($order->status === \App\Enums\OrderStatus::Pending)
            <div class="form-actions">
                <form method="POST" action="{{ route('admin.orders.accept', $order) }}" data-confirm="{{ __('Set up these services now, even if the invoice is not paid?') }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="check" />{{ __('Accept and set up') }}</button></form>
                <form method="POST" action="{{ route('admin.orders.cancel', $order) }}" data-confirm="{{ __('Cancel this order and its invoice?') }}">@csrf<button class="btn btn-danger" type="submit">{{ __('Cancel order') }}</button></form>
            </div>
        @endif
    </div>

    @if ($order->needs_review)
        <div class="flash" data-tone="warn" style="display:grid;gap:.4rem">
            <strong>{{ __('This order needs a review. Nothing is set up automatically until you accept it.') }}</strong>
            @foreach ((array) $order->fraud_reasons as $reason)
                <span>· {{ $reason }}</span>
            @endforeach
        </div>
    @endif

    <div class="grid-2">
        <section class="card card-flush">
            <div class="card-header"><h2>{{ __('Items') }}</h2></div>
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Service') }}</th><th>{{ __('Billing') }}</th><th>{{ __('Server') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($order->services as $service)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.services.show', $service) }}">{{ $service->product->name }}</a><div class="faint" style="font-size:.8rem">{{ $service->domain ?: '#'.$service->id }}</div></td>
                        <td class="num" style="white-space:nowrap">{{ money($service->first_payment_amount, $service->currency) }} · {{ $service->billing_cycle->label() }}</td>
                        <td>{{ $service->server?->name ?? '—' }}</td>
                        <td><x-status :value="$service->status" /></td>
                    </tr>
                @endforeach
                @foreach ($order->domains as $domain)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.domains.show', $domain) }}">{{ $domain->name }}</a><div class="faint" style="font-size:.8rem">{{ $domain->isTransfer() ? __('Domain transfer') : __('Domain registration') }}</div></td>
                        <td class="num" style="white-space:nowrap">{{ money($domain->first_payment_amount, $domain->currency) }} · {{ trans_choice(':count year|:count years', $domain->years, ['count' => $domain->years]) }}</td>
                        <td>{{ $domain->registrar ?: __('By hand') }}</td>
                        <td><x-status :value="$domain->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>

        <section class="card">
            <div class="card-header"><h2>{{ __('Payment') }}</h2></div>
            @if ($order->invoice)
                <dl class="dl">
                    <dt>{{ __('Invoice') }}</dt><dd><a class="mono" href="{{ route('admin.invoices.show', $order->invoice) }}">{{ $order->invoice->displayNumber() }}</a></dd>
                    <dt>{{ __('Total') }}</dt><dd class="num">{{ money($order->invoice->total, $order->invoice->currency) }}</dd>
                    <dt>{{ __('Status') }}</dt><dd><x-status :value="$order->invoice->status" /></dd>
                </dl>
            @else
                <p class="muted">{{ __('No invoice.') }}</p>
            @endif
        </section>
    </div>
</x-layouts.admin>
