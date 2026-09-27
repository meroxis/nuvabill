@extends('theme::layouts.app')

@section('title', $ticket->subject)

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Ticket') }} #{{ $ticket->number }} · {{ $ticket->department->name }}</p>
            <h1 style="margin-top:.3rem">{{ $ticket->subject }}</h1>
            @if ($ticket->service)<p>{{ __('About :service', ['service' => $ticket->service->label()]) }}</p>@endif
        </div>
        <div class="form-actions">
            <x-status :value="$ticket->status" />
            @if ($ticket->status !== \App\Enums\TicketStatus::Closed)
                <form method="POST" action="{{ route('client.tickets.close', $ticket) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Close ticket') }}</button></form>
            @endif
        </div>
    </div>

    <div class="thread">
        @foreach ($ticket->replies as $reply)
            <article class="reply {{ $reply->isFromStaff() ? 'staff' : '' }}">
                <header><b>{{ $reply->isFromStaff() ? $reply->authorName().' · '.setting('company.name') : __('You') }}</b><time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->translatedFormat('d M Y H:i') }}</time></header>
                <div class="message-body">{{ $reply->message }}</div>
            </article>
        @endforeach
    </div>

    <form method="POST" action="{{ route('client.tickets.reply', $ticket) }}" class="card" style="display:grid;gap:1rem">
        @csrf
        <x-textarea name="message" :label="$ticket->status === \App\Enums\TicketStatus::Closed ? __('Reply to open this ticket again') : __('Your reply')" rows="5" required />
        <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Send reply') }}</button></div>
    </form>
@endsection
