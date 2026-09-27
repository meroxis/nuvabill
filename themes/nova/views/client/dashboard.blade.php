@extends('theme::layouts.app')

@section('title', __('Client area'))

@section('content')
    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Client area') }}</p>
            <h1 style="margin-top:.3rem">{{ __('Welcome back, :name', ['name' => $client->first_name]) }}</h1>
        </div>
    </div>

    <div class="dash-grid">
        <div class="dash-main">
            <div class="stat-tiles">
                <a class="stat-tile" data-tone="info" href="{{ route('client.services.index') }}">
                    <span><b class="num">{{ $client->activeServicesCount() }}</b><span>{{ __('Services') }}</span></span>
                    <span class="stat-icon"><x-icon name="server" /></span>
                </a>
                <a class="stat-tile" data-tone="accent" href="{{ route('client.domains.index') }}">
                    <span><b class="num">{{ $domainCount }}</b><span>{{ __('Domains') }}</span></span>
                    <span class="stat-icon"><x-icon name="globe" /></span>
                </a>
                <a class="stat-tile" data-tone="warn" href="{{ route('client.tickets.index') }}">
                    <span><b class="num">{{ $openTicketCount }}</b><span>{{ __('Open tickets') }}</span></span>
                    <span class="stat-icon"><x-icon name="ticket" /></span>
                </a>
                <a class="stat-tile" data-tone="crit" href="{{ route('client.invoices.index') }}">
                    <span><b class="num">{{ $unpaidInvoices->count() }}</b><span>{{ trans_choice('Unpaid invoice|Unpaid invoices', $unpaidInvoices->count()) }}</span></span>
                    <span class="stat-icon"><x-icon name="receipt" /></span>
                </a>
            </div>

            @if ($unpaidInvoices->isNotEmpty())
                @php $first = $unpaidInvoices->first(); @endphp
                <div class="alert-bar" role="status">
                    <x-icon name="alert" />
                    <span>
                        {{ trans_choice('You have :count unpaid invoice for :amount.|You have :count unpaid invoices for :amount.', $unpaidInvoices->count(), ['count' => $unpaidInvoices->count(), 'amount' => money($unpaidTotal, $first->currency)]) }}
                        {{ $first->isOverdue() ? __('The oldest was due :date.', ['date' => $first->due_at->format('d M Y')]) : __('Due :date.', ['date' => $first->due_at->format('d M Y')]) }}
                        {{ __('Pay to keep your services running.') }}
                    </span>
                    <a class="btn btn-primary btn-sm" href="{{ route('client.invoices.show', $first) }}">{{ __('Pay now') }}</a>
                </div>
            @endif

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Your active products and services') }}</h2><a href="{{ route('client.services.index') }}">{{ __('View all') }}</a></div>
                @if ($services->isEmpty())
                    <div class="empty"><strong>{{ __('No services yet') }}</strong>{{ __('When you order hosting, it appears here.') }} <a href="{{ route('store.index') }}">{{ __('Browse the store') }}</a></div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Product') }}</th><th>{{ __('Price') }}</th><th>{{ __('Next due') }}</th><th>{{ __('Status') }}</th><th><span class="sr-only">{{ __('Actions') }}</span></th></tr></thead>
                        <tbody>
                        @foreach ($services as $service)
                            <tr>
                                <td><a class="row-link" href="{{ route('client.services.show', $service) }}">{{ $service->product->name }}</a>@if ($service->domain)<div class="muted" style="font-size:.84rem">{{ $service->domain }}</div>@endif</td>
                                <td class="num">{{ money($service->billing_cycle->isRecurring() ? $service->recurring_amount : $service->first_payment_amount, $service->currency) }} <span class="muted">· {{ $service->billing_cycle->label() }}</span></td>
                                <td class="num">{{ $service->next_due_date?->format('d M Y') ?? '—' }}</td>
                                <td><x-status :value="$service->status" /></td>
                                <td class="end"><a class="btn btn-sm" href="{{ route('client.services.show', $service) }}">{{ __('Manage') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            <div class="dash-pair">
                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Your domains') }}</h2><a href="{{ route('client.domains.index') }}">{{ __('All domains') }}</a></div>
                    @forelse ($domains as $domain)
                        @php $days = $domain->expires_at ? (int) today()->diffInDays($domain->expires_at, false) : null; @endphp
                        <div class="panel-row">
                            <div style="min-width:0;flex:1">
                                <a class="row-link" href="{{ route('client.domains.show', $domain) }}" style="overflow-wrap:anywhere">{{ $domain->name }}</a>
                                @if ($days === null)
                                    <div class="muted" style="font-size:.84rem">{{ $domain->status->label() }}</div>
                                @elseif ($days < 0)
                                    <div style="font-size:.84rem;color:var(--nb-crit);font-weight:600">{{ __('Expired :date', ['date' => $domain->expires_at->format('d M Y')]) }}</div>
                                @elseif ($days <= 30)
                                    <div style="font-size:.84rem;color:var(--nb-warn);font-weight:600">{{ trans_choice('Expires in :count day|Expires in :count days', $days, ['count' => $days]) }}</div>
                                @else
                                    <div class="muted" style="font-size:.84rem">{{ __('Expires :date', ['date' => $domain->expires_at->format('d M Y')]) }}</div>
                                @endif
                            </div>
                            @if ($days !== null && $days <= 30 && $domain->status->isRenewable())
                                <a class="btn btn-primary btn-sm" href="{{ route('client.domains.show', $domain) }}">{{ __('Renew') }}</a>
                            @else
                                <x-status :value="$domain->status" />
                            @endif
                        </div>
                    @empty
                        <div class="empty"><strong>{{ __('No domains yet') }}</strong>@if ($sellsDomains)<a href="{{ route('store.domains') }}">{{ __('Find your domain') }}</a>@endif</div>
                    @endforelse
                </section>

                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Recent invoices') }}</h2><a href="{{ route('client.invoices.index') }}">{{ __('All invoices') }}</a></div>
                    @forelse ($recentInvoices as $invoice)
                        <div class="panel-row">
                            <div style="min-width:0;flex:1">
                                <a class="row-link mono" href="{{ route('client.invoices.show', $invoice) }}">{{ $invoice->displayNumber() }}</a>
                                <div class="muted" style="font-size:.84rem">{{ $invoice->paid_at ? __('Paid :date', ['date' => $invoice->paid_at->format('d M Y')]) : __('Due :date', ['date' => $invoice->due_at->format('d M Y')]) }}</div>
                            </div>
                            <b class="num">{{ money($invoice->total, $invoice->currency) }}</b>
                            <x-status :value="$invoice->status" />
                        </div>
                    @empty
                        <div class="empty"><strong>{{ __('No invoices yet') }}</strong></div>
                    @endforelse
                </section>
            </div>

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Recent support tickets') }}</h2><a href="{{ route('client.tickets.create') }}">{{ __('Open a ticket') }}</a></div>
                @forelse ($openTickets as $ticket)
                    <div class="panel-row">
                        <div style="min-width:0;flex:1">
                            <a class="row-link" href="{{ route('client.tickets.show', $ticket) }}">{{ $ticket->subject }}</a>
                            <div class="muted" style="font-size:.84rem">#{{ $ticket->number }}@if ($ticket->last_reply_at) · {{ __('Last reply :time', ['time' => $ticket->last_reply_at->diffForHumans()]) }}@endif</div>
                        </div>
                        <x-status :value="$ticket->status" />
                    </div>
                @empty
                    <div class="empty">{{ __('No open tickets. Need help? Open a ticket and we will reply by email.') }}</div>
                @endforelse
            </section>
        </div>

        <aside class="dash-side" aria-label="{{ __('Your account') }}">
            <section class="card card-flush">
                <div class="card-header"><h2 class="side-title"><x-icon name="user" />{{ __('Your info') }}</h2></div>
                <div class="side-body">
                    <b>{{ $client->name }}</b>
                    @if ($client->company_name)<span>{{ $client->company_name }}</span>@endif
                    <span style="overflow-wrap:anywhere">{{ $client->email }}</span>
                    @if ($client->address_1)<span>{{ $client->address_1 }}</span>@endif
                    @if ($client->city || $client->postcode)<span>{{ trim($client->city.' '.$client->postcode) }}</span>@endif
                    @if ($country)<span>{{ $country }}</span>@endif
                </div>
                <div style="padding:0 1.1rem 1.1rem"><a class="btn btn-block" href="{{ route('client.account.edit') }}">{{ __('Update details') }}</a></div>
            </section>

            <section class="card card-flush">
                <div class="card-header"><h2 class="side-title"><x-icon name="zap" />{{ __('Shortcuts') }}</h2></div>
                <nav class="shortcut-list" aria-label="{{ __('Shortcuts') }}">
                    <a href="{{ route('store.index') }}"><x-icon name="cart" />{{ __('Order new services') }}</a>
                    @if ($sellsDomains)
                        <a href="{{ route('store.domains') }}"><x-icon name="globe" />{{ __('Register a new domain') }}</a>
                    @endif
                    <a href="{{ route('client.tickets.create') }}"><x-icon name="ticket" />{{ __('Open a support ticket') }}</a>
                    <form method="POST" action="{{ route('client.logout') }}">
                        @csrf
                        <button type="submit"><x-icon name="logout" />{{ __('Sign out') }}</button>
                    </form>
                </nav>
            </section>

            @if ($twoFactorReminder)
                <section class="card notice-card">
                    <span class="notice-icon"><x-icon name="shield" /></span>
                    <div>
                        <b>{{ __('Two-factor sign-in is off') }}</b>
                        <span>{{ __('Protect your account in one minute.') }}</span>
                        <a href="{{ route('client.account.edit') }}#two-factor">{{ __('Turn it on') }}</a>
                    </div>
                </section>
            @endif
        </aside>
    </div>
@endsection
