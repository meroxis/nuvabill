<x-layouts.admin :title="$client->name">
    @php
        $admin = auth('admin')->user();
        // Invoices, payments and the wallet are shown only to staff who may see billing.
        $canBilling = $admin->hasPermission('billing.view');
    @endphp
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Client') }} #{{ $client->id }}</p>
            <h1 style="margin-top:.2rem">{{ $client->name }} <x-status :value="$client->status" style="vertical-align:middle" /></h1>
            <p>{{ $client->company_name ? $client->company_name.' · ' : '' }}@if ($client->isErased())<x-pill>{{ __('Personal data erased') }}</x-pill>@else{{ $client->email }}@endif</p>
            @if ($client->tagList() !== [])
                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:.4rem">@foreach ($client->tagList() as $tag)<x-pill tone="info">{{ $tag }}</x-pill>@endforeach</div>
            @endif
        </div>
        <div class="form-actions">
            @if ($admin->hasPermission('billing.manage'))
                <a class="btn" href="{{ route('admin.invoices.create', ['client' => $client->id]) }}"><x-icon name="receipt" />{{ __('New invoice') }}</a>
            @endif
            @if ($admin->hasPermission('clients.manage'))
                @if ($client->hasTwoFactorEnabled())
                    <form method="POST" action="{{ route('admin.clients.two-factor.destroy', $client) }}" data-confirm="{{ __('Turn off two-factor sign-in for this client? Only do this after you checked it is really them, for example when they lost their phone.') }}">
                        @csrf
                        @method('DELETE')
                        <button class="btn" type="submit"><x-icon name="shield" />{{ __('Reset two-factor') }}</button>
                    </form>
                @endif
                <a class="btn btn-primary" href="{{ route('admin.clients.edit', $client) }}">{{ __('Edit client') }}</a>
            @endif
        </div>
    </div>

    <div class="kpis">
        <div class="kpi"><small>{{ __('Active services') }}</small><b>{{ $client->services->where('status', \App\Enums\ServiceStatus::Active)->count() }}</b><span>{{ trans_choice(':count in total|:count in total', $client->services->count(), ['count' => $client->services->count()]) }}</span></div>
        @if ($canBilling)
            <div class="kpi"><small>{{ __('Unpaid') }}</small><b>{{ money($unpaid, $client->currency) }}</b><span>{{ __('Credit: :amount', ['amount' => money($client->credit, $client->currency)]) }}</span></div>
        @endif
        <div class="kpi"><small>{{ __('Client since') }}</small><b style="font-size:1.2rem">{{ $client->created_at->translatedFormat('d M Y') }}</b><span>{{ $client->last_login_at ? __('Last sign in :time', ['time' => $client->last_login_at->diffForHumans()]) : __('Never signed in') }}</span></div>
        <div class="kpi"><small>{{ __('Open tickets') }}</small><b>{{ $client->openTicketsCount() }}</b><span>{{ $client->phone ?: __('No phone number') }}</span></div>
    </div>

    <div class="grid-2">
        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Services') }}</h2></div>
                @if ($client->services->isEmpty())
                    <div class="empty">{{ __('No services yet.') }}</div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Service') }}</th><th>{{ __('Price') }}</th><th>{{ __('Next due') }}</th><th>{{ __('Status') }}</th></tr></thead>
                        <tbody>
                        @foreach ($client->services as $service)
                            <tr>
                                <td>
                                    @if ($admin->hasPermission('services.manage'))
                                        <a class="row-link" href="{{ route('admin.services.show', $service) }}">{{ $service->product->name }}</a>
                                    @else
                                        <b>{{ $service->product->name }}</b>
                                    @endif
                                    <div class="faint" style="font-size:.8rem">{{ $service->domain ?: '#'.$service->id }}</div>
                                </td>
                                <td class="num" style="white-space:nowrap">{{ money($service->recurring_amount ?: $service->first_payment_amount, $service->currency) }}{{ $service->billing_cycle->suffix() }}</td>
                                <td class="num" style="white-space:nowrap">{{ $service->next_due_date?->translatedFormat('d M Y') ?? '—' }}</td>
                                <td><x-status :value="$service->status" /></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            @if ($canBilling)
                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Invoices') }}</h2></div>
                    @include('admin.invoices.partials.table', ['invoices' => $client->invoices, 'showClient' => false])
                </section>

                <section class="card card-flush">
                    <div class="card-header"><h2>{{ __('Payments') }}</h2></div>
                    @if ($client->transactions->isEmpty())
                        <div class="empty">{{ __('No payments yet.') }}</div>
                    @else
                        <div class="table-wrap"><table class="table">
                            <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Method') }}</th><th>{{ __('Reference') }}</th><th class="end">{{ __('Amount') }}</th></tr></thead>
                            <tbody>
                            @foreach ($client->transactions as $transaction)
                                <tr>
                                    <td style="white-space:nowrap">{{ $transaction->paid_at->translatedFormat('d M Y') }}</td>
                                    <td>{{ $transaction->gatewayLabel() }}</td>
                                    <td class="mono faint">{{ \Illuminate\Support\Str::limit($transaction->reference ?? '—', 24) }}</td>
                                    <td class="end num">{{ money($transaction->amount, $transaction->currency) }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table></div>
                    @endif
                </section>
            @endif
        </div>

        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            <section class="card">
                <div class="card-header"><h2>{{ __('Details') }}</h2></div>
                <dl class="dl">
                    <dt>{{ __('Email') }}</dt><dd>{{ $client->email }}</dd>
                    <dt>{{ __('Phone') }}</dt><dd>{{ $client->phone ?: '—' }}</dd>
                    <dt>{{ __('Address') }}</dt>
                    <dd>
                        {{ collect([$client->address_1, $client->address_2, trim($client->postcode.' '.$client->city), $client->state, \App\Support\Countries::name($client->country)])->filter()->implode(', ') ?: '—' }}
                    </dd>
                    <dt>{{ __('Currency') }}</dt><dd>{{ $client->currency }}</dd>
                    @if ($client->tax_id || $client->tax_exempt)
                        <dt>{{ setting('tax.id_label') }}</dt><dd>{{ $client->tax_id ?: '—' }}@if ($client->tax_exempt) <x-pill>{{ __('Tax exempt') }}</x-pill>@endif</dd>
                    @endif
                </dl>
                @if ($client->notes)
                    <div class="flash" data-tone="warn" style="margin-top:1rem"><span class="message-body">{{ $client->notes }}</span></div>
                @endif
            </section>

            @if ($canBilling)
                <section class="card" style="display:grid;gap:.8rem">
                    <div class="card-header" style="margin:0"><h2>{{ __('Wallet') }}</h2><b class="num">{{ money($client->credit, $client->currency) }}</b></div>
                    @if ($walletEntries->isNotEmpty())
                        <ul class="list-plain">
                            @foreach ($walletEntries as $entry)
                                <li class="feed-item">
                                    <span>{{ $entry->description }}@if ($entry->admin) <span class="faint">· {{ $entry->admin->name }}</span>@endif</span>
                                    <span class="num" style="white-space:nowrap;color:{{ $entry->amount >= 0 ? 'var(--nb-good)' : 'inherit' }}">{{ $entry->amount >= 0 ? '+' : '' }}{{ money($entry->amount, $entry->currency) }}</span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($admin->hasPermission('billing.manage'))
                        <form method="POST" action="{{ route('admin.clients.wallet', $client) }}" style="display:grid;grid-template-columns:120px minmax(0,1fr) auto;gap:8px;align-items:end">
                            @csrf
                            <x-input name="amount" type="number" step="0.01" :label="__('Amount')" required placeholder="10.00" />
                            <x-input name="reason" :label="__('Reason')" required :placeholder="__('For example Refund for downtime')" />
                            <button class="btn" type="submit">{{ __('Save') }}</button>
                        </form>
                        <p class="help" style="margin:0">{{ __('Use a minus to take money away, for example -5.00.') }}</p>
                    @endif
                </section>

                @php $savedMethods = $client->paymentMethods()->orderByDesc('is_default')->latest('id')->get(); @endphp
                <section class="card" style="display:grid;gap:.7rem">
                    <div class="card-header" style="margin:0"><h2>{{ __('Saved payment methods') }}</h2>
                        @if ($savedMethods->isNotEmpty())
                            <x-pill :tone="$client->auto_pay ? 'good' : null">{{ $client->auto_pay ? __('Automatic payments on') : __('Automatic payments off') }}</x-pill>
                        @endif
                    </div>
                    @forelse ($savedMethods as $method)
                        <div class="feed-item" style="align-items:center">
                            <span style="min-width:0">{{ $method->label() }}@if ($method->expiry())<span class="faint"> · {{ $method->isExpired() ? __('Expired :date', ['date' => $method->expiry()]) : __('Expires :date', ['date' => $method->expiry()]) }}</span>@endif
                                @if ($method->is_default)<x-pill tone="good">{{ __('Pays renewals') }}</x-pill>@endif</span>
                            @if ($admin->hasPermission('billing.manage'))
                                <form method="POST" action="{{ route('admin.clients.payment-methods.destroy', [$client, $method]) }}" data-confirm="{{ __('Remove :method? It will not pay this client’s renewals anymore.', ['method' => $method->label()]) }}">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-ghost" type="submit" aria-label="{{ __('Remove :name', ['name' => $method->label()]) }}"><x-icon name="trash" /></button>
                                </form>
                            @endif
                        </div>
                    @empty
                        <p class="muted" style="margin:0;font-size:.88rem">{{ __('Nothing saved. Clients save a card or PayPal account themselves when they pay, or in Account → Payment methods.') }}</p>
                    @endforelse
                    @if ($savedMethods->isNotEmpty())
                        <p class="help" style="margin:0">{{ __('Card numbers are never stored in Nuvabill. You can charge an invoice now from the invoice page, or remove a saved method; only the client can add one.') }}</p>
                    @endif
                </section>
            @endif

            <section class="card">
                <div class="card-header"><h2>{{ __('Tickets') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($client->tickets as $ticket)
                        <li class="feed-item">
                            <span>
                                @if ($admin->hasPermission('support.manage'))
                                    <a class="row-link" href="{{ route('admin.tickets.show', $ticket) }}">{{ $ticket->subject }}</a>
                                @else
                                    {{ $ticket->subject }}
                                @endif
                                <span class="faint">· {{ $ticket->department->name }}</span>
                            </span>
                            <x-status :value="$ticket->status" />
                        </li>
                    @empty
                        <li class="muted">{{ __('No tickets.') }}</li>
                    @endforelse
                </ul>
            </section>

            <section class="card">
                <div class="card-header"><h2>{{ __('Activity') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($activity as $entry)
                        <li class="feed-item">
                            <span>{{ $entry->description }}</span>
                            <time datetime="{{ $entry->created_at->toIso8601String() }}">{{ $entry->created_at->diffForHumans(short: true) }}</time>
                        </li>
                    @empty
                        <li class="muted">{{ __('Nothing yet.') }}</li>
                    @endforelse
                </ul>
            </section>

            @if ($admin->hasPermission('clients.manage'))
                <section class="card" style="display:grid;gap:.8rem" x-data="{ erase: false }">
                    <div class="card-header" style="margin:0"><h2>{{ __('Privacy') }}</h2></div>
                    @if ($client->isErased())
                        <p class="muted" style="margin:0">{{ __('Personal data was erased on :date. Invoices and payments stay, as the law requires.', ['date' => $client->erased_at->translatedFormat('d M Y')]) }}</p>
                    @else
                        <p class="muted" style="margin:0;font-size:.9rem">{{ __('When a client asks what you keep about them, or asks you to delete it (GDPR and similar laws).') }}</p>
                        <div class="form-actions">
                            <a class="btn btn-sm" href="{{ route('admin.clients.data', $client) }}"><x-icon name="download" />{{ __('Download their data') }}</a>
                            <button class="btn btn-sm btn-danger" type="button" @click="erase = ! erase">{{ __('Erase personal data') }}</button>
                        </div>
                        <div x-show="erase" x-cloak style="display:grid;gap:.7rem">
                            @if ($privacyBlockers !== [])
                                <ul style="margin:0;padding-inline-start:1.1rem;font-size:.9rem">
                                    @foreach ($privacyBlockers as $blocker)<li>{{ $blocker }}</li>@endforeach
                                </ul>
                            @else
                                <p style="margin:0;font-size:.9rem">{{ __('This removes their email, phone, password, tickets, saved cards and sign-in methods. They can no longer sign in. The name and address stay only on invoices, which the law requires you to keep. This cannot be undone.') }}</p>
                                <form method="POST" action="{{ route('admin.clients.erase', $client) }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end">
                                    @csrf
                                    <x-input name="confirm" :label="__('Type ERASE to confirm')" autocomplete="off" required style="max-width:180px" />
                                    <button class="btn btn-danger" type="submit">{{ __('Erase now') }}</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </section>
            @endif
        </div>
    </div>
</x-layouts.admin>
