<x-layouts.admin :title="__('Clients')">
    <div class="page-head">
        <div>
            <h1>{{ __('Clients') }}</h1>
            <p>{{ trans_choice(':count client|:count clients', $clients->total(), ['count' => number_format($clients->total())]) }}</p>
        </div>
        @if (auth('admin')->user()->hasPermission('clients.manage'))
            <a class="btn btn-primary" href="{{ route('admin.clients.create') }}"><x-icon name="plus" />{{ __('Add client') }}</a>
        @endif
    </div>

    <div class="filters">
        <a class="chip" href="{{ route('admin.clients.index', array_filter(['q' => $search])) }}" @if (! $status) aria-current="true" @endif>{{ __('All') }}</a>
        @foreach (\App\Enums\ClientStatus::cases() as $case)
            <a class="chip" href="{{ route('admin.clients.index', array_filter(['q' => $search, 'status' => $case->value])) }}" @if ($status === $case) aria-current="true" @endif>{{ $case->label() }}</a>
        @endforeach
        @if ($search !== '')
            <span class="muted" style="font-size:.85rem">{{ __('Results for ":q"', ['q' => $search]) }} · <a href="{{ route('admin.clients.index') }}">{{ __('Clear') }}</a></span>
        @endif
    </div>

    <section class="card card-flush">
        @if ($clients->isEmpty())
            <div class="empty"><strong>{{ __('No clients found') }}</strong>{{ $search !== '' ? __('Try a different name or email.') : __('Clients appear here after they order or you add them.') }}</div>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr><th>{{ __('Client') }}</th><th>{{ __('Email') }}</th><th class="end">{{ __('Active services') }}</th><th>{{ __('Status') }}</th><th>{{ __('Since') }}</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($clients as $client)
                            <tr>
                                <td>
                                    <a class="row-link" href="{{ route('admin.clients.show', $client) }}">{{ $client->name }}</a>
                                    <div class="faint" style="font-size:.8rem">#{{ $client->id }}@if ($client->company_name) · {{ $client->company_name }}@endif</div>
                                </td>
                                <td>{{ $client->email }}</td>
                                <td class="end num">{{ $client->active_services_count }}</td>
                                <td><x-status :value="$client->status" /></td>
                                <td class="muted" style="white-space:nowrap">{{ $client->created_at->translatedFormat('d M Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $clients->links() }}
        @endif
    </section>
</x-layouts.admin>
