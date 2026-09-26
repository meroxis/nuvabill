@extends('theme::layouts.app')

@section('title', __('My account'))

@section('content')
    <div class="page-title">
        <div>
            <h1>{{ __('Hello, :name', ['name' => $client->first_name]) }}</h1>
            <p>{{ __('Here is everything in your account.') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('store.index') }}"><x-icon name="plus" />{{ __('Order something new') }}</a>
    </div>

    @foreach ($unpaidInvoices as $invoice)
        <div class="due-banner">
            <span>{{ __('Invoice') }} <b class="mono">{{ $invoice->displayNumber() }}</b> · <b class="num">{{ money($invoice->balance(), $invoice->currency) }}</b>
                · {{ $invoice->isOverdue() ? __('overdue since :date', ['date' => $invoice->due_at->format('d M Y')]) : __('due :date', ['date' => $invoice->due_at->format('d M Y')]) }}</span>
            <a class="btn btn-primary btn-sm" href="{{ route('client.invoices.show', $invoice) }}">{{ __('Pay now') }}</a>
        </div>
    @endforeach

    <div class="kpis">
        <div class="kpi"><small>{{ __('Active services') }}</small><b>{{ $client->activeServicesCount() }}</b></div>
        <div class="kpi"><small>{{ __('Amount due') }}</small><b>{{ money($unpaidTotal, $client->currency) }}</b></div>
        <div class="kpi"><small>{{ __('Open tickets') }}</small><b>{{ $openTickets->count() }}</b></div>
        <div class="kpi"><small>{{ __('Account credit') }}</small><b>{{ money($client->credit, $client->currency) }}</b></div>
    </div>

    <section style="display:grid;gap:12px">
        <div class="page-title"><h2 style="font-size:1.2rem">{{ __('Your services') }}</h2><a class="btn btn-sm" href="{{ route('client.services.index') }}">{{ __('See all') }}</a></div>
        @if ($services->isEmpty())
            <div class="card empty"><strong>{{ __('No services yet') }}</strong>{{ __('When you order hosting, it appears here.') }}</div>
        @else
            <div class="service-grid">
                @foreach ($services as $service)
                    @include('theme::client.services.card', ['service' => $service])
                @endforeach
            </div>
        @endif
    </section>

    <section style="display:grid;gap:12px">
        <div class="page-title"><h2 style="font-size:1.2rem">{{ __('Support') }}</h2><a class="btn btn-sm" href="{{ route('client.tickets.create') }}"><x-icon name="plus" />{{ __('New ticket') }}</a></div>
        <div class="card">
            @forelse ($openTickets as $ticket)
                <div class="summary-row" style="padding:.4rem 0">
                    <a href="{{ route('client.tickets.show', $ticket) }}">{{ $ticket->subject }}</a>
                    <x-status :value="$ticket->status" />
                </div>
            @empty
                <p class="muted" style="margin:0">{{ __('No open tickets. Need help? Open a ticket and we will reply by email.') }}</p>
            @endforelse
        </div>
    </section>
@endsection
