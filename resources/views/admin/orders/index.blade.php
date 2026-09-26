<x-layouts.admin :title="__('Orders')">
    <div class="page-head">
        <div>
            <h1>{{ __('Orders') }}</h1>
            <p>{{ __('New orders from the store. Paid orders are set up automatically when the product allows it.') }}</p>
        </div>
    </div>

    <div class="filters">
        <a class="chip" href="{{ route('admin.orders.index') }}" @if (! $status) aria-current="true" @endif>{{ __('All') }}</a>
        @foreach (\App\Enums\OrderStatus::cases() as $case)
            <a class="chip" href="{{ route('admin.orders.index', ['status' => $case->value]) }}" @if ($status === $case) aria-current="true" @endif>{{ $case->label() }}</a>
        @endforeach
    </div>

    <section class="card card-flush">
        @if ($orders->isEmpty())
            <div class="empty"><strong>{{ __('No orders') }}</strong>{{ __('Orders placed in your store appear here.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Order') }}</th><th>{{ __('Client') }}</th><th>{{ __('Date') }}</th><th class="end">{{ __('Total') }}</th><th>{{ __('Payment') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td><a class="row-link mono" href="{{ route('admin.orders.show', $order) }}">#{{ $order->number }}</a><div class="faint" style="font-size:.8rem">{{ trans_choice(':count item|:count items', $order->services_count, ['count' => $order->services_count]) }}</div></td>
                        <td>{{ $order->client->name }}</td>
                        <td style="white-space:nowrap">{{ $order->created_at->format('d M Y H:i') }}</td>
                        <td class="end num">{{ money($order->total, $order->currency) }}</td>
                        <td>@if ($order->invoice)<x-status :value="$order->invoice->status" />@else — @endif</td>
                        <td><x-status :value="$order->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $orders->links() }}
        @endif
    </section>
</x-layouts.admin>
