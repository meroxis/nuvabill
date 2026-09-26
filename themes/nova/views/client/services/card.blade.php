<a class="service-card" href="{{ route('client.services.show', $service) }}">
    <div class="top">
        <div>
            <b>{{ $service->product->name }}</b>
            <div class="muted mono" style="font-size:.8rem">{{ $service->domain ?: '#'.$service->id }}</div>
        </div>
        <x-status :value="$service->status" />
    </div>
    <div class="muted" style="font-size:.85rem">
        @if ($service->next_due_date && $service->billing_cycle->isRecurring())
            {{ __('Renews :date for :amount', ['date' => $service->next_due_date->format('d M Y'), 'amount' => money($service->recurring_amount, $service->currency)]) }}
        @else
            {{ $service->billing_cycle->label() }}
        @endif
    </div>
</a>
