<x-layouts.admin :title="$ticket->subject">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Ticket') }} #{{ $ticket->number }} · {{ $ticket->department->name }}</p>
            <h1 style="margin-top:.2rem">{{ $ticket->subject }}</h1>
            <p><a href="{{ route('admin.clients.show', $ticket->client) }}">{{ $ticket->client->name }}</a> · {{ $ticket->client->email }}
                @if ($ticket->service) · {{ __('About :service', ['service' => $ticket->service->label()]) }}@endif
            </p>
        </div>
        <div class="form-actions">
            <x-status :value="$ticket->status" />
            <x-status :value="$ticket->priority" />
            @if ($ticket->status !== \App\Enums\TicketStatus::Closed)
                <form method="POST" action="{{ route('admin.tickets.close', $ticket) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Close ticket') }}</button></form>
            @endif
        </div>
    </div>

    <div class="thread">
        @foreach ($ticket->replies as $reply)
            <article class="reply {{ $reply->isFromStaff() ? 'staff' : '' }}">
                <span class="avatar">{{ mb_strtoupper(mb_substr($reply->authorName(), 0, 1)) }}</span>
                <div class="bubble">
                    <header><b>{{ $reply->authorName() }}</b><span>{{ $reply->isFromStaff() ? __('Staff') : __('Client') }}</span><time datetime="{{ $reply->created_at->toIso8601String() }}">{{ $reply->created_at->translatedFormat('d M Y H:i') }}</time></header>
                    <div class="message-body">{{ $reply->message }}</div>
                </div>
            </article>
        @endforeach
    </div>

    <form method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" class="card" style="display:grid;gap:1rem">
        @csrf
        <x-textarea name="message" :label="__('Your reply')" rows="6" required :placeholder="__('Hi :name,', ['name' => $ticket->client->first_name])" />
        <div class="form-actions">
            <select name="status" class="select" style="width:auto" aria-label="{{ __('Status after reply') }}">
                <option value="answered">{{ __('Send and mark answered') }}</option>
                <option value="on_hold">{{ __('Send and put on hold') }}</option>
                <option value="closed">{{ __('Send and close') }}</option>
            </select>
            <button class="btn btn-primary" type="submit"><x-icon name="mail" />{{ __('Send reply') }}</button>
        </div>
    </form>
</x-layouts.admin>
