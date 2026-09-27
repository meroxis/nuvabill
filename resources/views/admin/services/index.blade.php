<x-layouts.admin :title="__('Services')">
    <div class="page-head">
        <div>
            <h1>{{ __('Services') }}</h1>
            <p>{{ __('Every hosting account and product your clients own.') }}</p>
        </div>
    </div>

    <div class="filters">
        <a class="chip" href="{{ route('admin.services.index') }}" @if (! $status) aria-current="true" @endif>{{ __('All') }}</a>
        @foreach (\App\Enums\ServiceStatus::cases() as $case)
            <a class="chip" href="{{ route('admin.services.index', ['status' => $case->value]) }}" @if ($status === $case) aria-current="true" @endif>{{ $case->label() }}</a>
        @endforeach
        <form method="GET" action="{{ route('admin.services.index') }}" style="margin-inline-start:auto">
            @if ($status)<input type="hidden" name="status" value="{{ $status->value }}">@endif
            <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Domain or username') }}" aria-label="{{ __('Search services') }}" style="width:220px">
        </form>
    </div>

    <section class="card card-flush">
        @if ($services->isEmpty())
            <div class="empty"><strong>{{ __('No services') }}</strong>{{ __('Services are created when clients order.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Service') }}</th><th>{{ __('Client') }}</th><th>{{ __('Price') }}</th><th>{{ __('Next due') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($services as $service)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.services.show', $service) }}">{{ $service->domain ?: $service->product->name }}</a><div class="faint" style="font-size:.8rem">{{ $service->product->name }}@if ($service->username) · {{ $service->username }}@endif</div></td>
                        <td>{{ $service->client->name }}</td>
                        <td class="num" style="white-space:nowrap">{{ money($service->recurring_amount, $service->currency) }}{{ $service->billing_cycle->suffix() }}</td>
                        <td class="num" style="white-space:nowrap">{{ $service->next_due_date?->translatedFormat('d M Y') ?? '—' }}</td>
                        <td><x-status :value="$service->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $services->links() }}
        @endif
    </section>
</x-layouts.admin>
