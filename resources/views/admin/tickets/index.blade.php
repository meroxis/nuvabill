<x-layouts.admin :title="__('Support')">
    <div class="page-head">
        <div>
            <h1>{{ __('Support') }}</h1>
            <p>{{ __('Tickets from your clients. Those waiting for a reply are at the top.') }}</p>
        </div>
    </div>

    @include('admin.support.nav')

    <div class="filters">
        @foreach (['waiting' => __('Waiting for reply'), 'answered' => __('Answered'), 'on_hold' => __('On hold'), 'closed' => __('Closed'), 'all' => __('All')] as $key => $label)
            <a class="chip" href="{{ route('admin.tickets.index', array_filter(['status' => $key, 'department' => $department])) }}" @if ($filter === $key) aria-current="true" @endif>{{ $label }}</a>
        @endforeach
        @if (count($departments) > 1)
            <form method="GET" action="{{ route('admin.tickets.index') }}" style="margin-inline-start:auto">
                <input type="hidden" name="status" value="{{ $filter }}">
                <select class="select" name="department" onchange="this.form.submit()" aria-label="{{ __('Department') }}" style="width:auto">
                    <option value="">{{ __('All departments') }}</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}" @selected($department === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
        @endif
    </div>

    <section class="card card-flush">
        @if ($tickets->isEmpty())
            <div class="empty"><strong>{{ $filter === 'waiting' ? __('All caught up') : __('No tickets') }}</strong>{{ $filter === 'waiting' ? __('No client is waiting for a reply.') : '' }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Subject') }}</th><th>{{ __('Client') }}</th><th>{{ __('Department') }}</th><th>{{ __('Last reply') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($tickets as $ticket)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.tickets.show', $ticket) }}">{{ $ticket->subject }}</a><div class="faint" style="font-size:.8rem"><span class="mono">#{{ $ticket->number }}</span> · <x-status :value="$ticket->priority" />@if ($ticket->assignee) · {{ $ticket->assignee->name }}@endif</div></td>
                        <td>{{ $ticket->client->name }}</td>
                        <td>{{ $ticket->department->name }}</td>
                        <td style="white-space:nowrap">{{ $ticket->last_reply_at?->diffForHumans() }}</td>
                        <td><x-status :value="$ticket->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $tickets->links() }}
        @endif
    </section>
</x-layouts.admin>
