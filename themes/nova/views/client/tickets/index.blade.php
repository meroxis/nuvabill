@extends('theme::layouts.app')

@section('title', __('Support'))

@section('content')
    <div class="page-title">
        <div><h1>{{ __('Support') }}</h1><p>{{ __('Ask us anything. We reply here and by email.') }}</p></div>
        <a class="btn btn-primary" href="{{ route('client.tickets.create') }}"><x-icon name="plus" />{{ __('New ticket') }}</a>
    </div>

    <section class="card card-flush">
        @if ($tickets->isEmpty())
            <div class="empty"><strong>{{ __('No tickets yet') }}</strong>{{ __('Open a ticket if you need help.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Subject') }}</th><th>{{ __('Department') }}</th><th>{{ __('Last update') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($tickets as $ticket)
                    <tr>
                        <td><a class="row-link" href="{{ route('client.tickets.show', $ticket) }}">{{ $ticket->subject }}</a><div class="faint mono" style="font-size:.78rem">#{{ $ticket->number }}</div></td>
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
@endsection
