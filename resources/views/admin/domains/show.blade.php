<x-layouts.admin :title="$domain->name">
    @php
        $status = $domain->status;
        $nameservers = array_pad($domain->nameserverList(), 4, '');
    @endphp

    <div class="page-head">
        <div>
            <p class="eyebrow">{{ $domain->isTransfer() ? __('Domain transfer') : __('Domain') }} #{{ $domain->id }}</p>
            <h1 style="margin-top:.2rem;overflow-wrap:anywhere">{{ $domain->name }} <x-status :value="$status" style="vertical-align:middle" /></h1>
            <p><a href="{{ route('admin.clients.show', $domain->client) }}">{{ $domain->client->name }}</a>
                @if ($domain->order) · <a href="{{ route('admin.orders.show', $domain->order) }}">{{ __('Order #:number', ['number' => $domain->order->number]) }}</a>@endif
            </p>
        </div>
    </div>

    @if ($domain->order?->needs_review)
        <div class="flash" data-tone="warn"><span>{{ __('The order is waiting for a fraud review. Nothing is registered until you accept it.') }}</span></div>
    @endif

    <section class="card">
        <div class="card-header">
            <h2>{{ __('Registrar actions') }}</h2>
            <span class="pill">{{ $domain->registrar ? __('Registrar: :name', ['name' => $registrars[$domain->registrar] ?? $domain->registrar]) : __('Registered by hand') }}</span>
        </div>
        <div class="form-actions">
            @if (in_array($status, [\App\Enums\DomainStatus::Pending, \App\Enums\DomainStatus::PendingTransfer], true) && $domain->registrar)
                <form method="POST" action="{{ route('admin.domains.action', [$domain, 'register']) }}" data-confirm="{{ $domain->isTransfer() ? __('Start the transfer at the registrar now?') : __('Register this domain at the registrar now? Your registrar account is charged.') }}">@csrf<button class="btn btn-primary" type="submit"><x-icon name="check" />{{ $domain->isTransfer() ? __('Start transfer') : __('Register now') }}</button></form>
            @endif
            @if ($status->isRenewable())
                <form method="POST" action="{{ route('admin.domains.action', [$domain, 'invoice']) }}">@csrf<button class="btn" type="submit">{{ __('Create renewal invoice') }}</button></form>
                <form method="POST" action="{{ route('admin.domains.action', [$domain, 'renew']) }}" style="display:flex;gap:6px" data-confirm="{{ __('Renew at the registrar now, without an invoice? Your registrar account is charged.') }}">
                    @csrf
                    <select class="select" name="years" aria-label="{{ __('Years') }}" style="width:auto">
                        @foreach (range(1, 5) as $years)
                            <option value="{{ $years }}" @selected($years === $domain->years)>{{ trans_choice(':count year|:count years', $years, ['count' => $years]) }}</option>
                        @endforeach
                    </select>
                    <button class="btn" type="submit">{{ __('Renew now') }}</button>
                </form>
            @endif
            @if ($domain->registrar && $status !== \App\Enums\DomainStatus::Pending)
                <form method="POST" action="{{ route('admin.domains.action', [$domain, 'sync']) }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Check at registrar') }}</button></form>
            @endif
        </div>
        @if ($domain->last_synced_at)
            <p class="faint" style="margin:.8rem 0 0;font-size:.82rem">{{ __('Last checked :time.', ['time' => $domain->last_synced_at->diffForHumans()]) }}</p>
        @endif
    </section>

    <div class="grid-2">
        <form method="POST" action="{{ route('admin.domains.update', $domain) }}" class="card" style="display:grid;gap:1.1rem">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Details') }}</h2></div>
            <div class="form-grid">
                <x-select name="registrar" :label="__('Registrar')" :options="$registrars" :value="$domain->registrar" :placeholder="__('None (by hand)')" />
                <x-select name="status" :label="__('Status')" :options="collect(\App\Enums\DomainStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="$status" :help="__('Changing this only updates Nuvabill.')" required />
                <x-input name="registered_at" type="date" :label="__('Registered')" :value="$domain->registered_at?->toDateString()" />
                <x-input name="expires_at" type="date" :label="__('Expires')" :value="$domain->expires_at?->toDateString()" />
                <x-input name="next_due_date" type="date" :label="__('Next due date')" :value="$domain->next_due_date?->toDateString()" />
                <x-input name="years" type="number" min="1" max="10" :label="__('Renewal period (years)')" :value="$domain->years" required />
                <x-input name="recurring_amount" type="number" step="0.01" min="0" :label="__('Renewal price (:currency)', ['currency' => $domain->currency])" :value="\App\Support\Money::toDecimal($domain->recurring_amount)" required />
                @if ($domain->isTransfer())
                    <x-input name="epp_code" type="password" :label="__('Authorization (EPP) code')" :help="$domain->epp_code ? __('Saved. Leave empty to keep it.') : null" autocomplete="off" />
                @endif
                <x-checkbox name="auto_renew" :label="__('Send renewal invoices automatically')" :checked="$domain->auto_renew" class="span-2" />
            </div>
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save details') }}</button></div>
        </form>

        <div style="display:grid;gap:14px;align-content:start">
            <form method="POST" action="{{ route('admin.domains.action', [$domain, 'nameservers']) }}" class="card" style="display:grid;gap:.9rem">
                @csrf
                <div class="card-header" style="margin:0"><h2>{{ __('Nameservers') }}</h2></div>
                @foreach ($nameservers as $index => $nameserver)
                    <x-input :name="'nameservers['.$index.']'" :id="'ns-'.$index" :label="__('Nameserver :number', ['number' => $index + 1])" :value="$nameserver" autocomplete="off" spellcheck="false" />
                @endforeach
                <div class="form-actions"><button class="btn" type="submit">{{ $domain->registrar && $status === \App\Enums\DomainStatus::Active ? __('Save at registrar') : __('Save') }}</button></div>
            </form>

            <section class="card">
                <div class="card-header"><h2>{{ __('Invoices') }}</h2></div>
                <ul class="list-plain">
                    @forelse ($domain->invoiceItems->sortByDesc('id')->take(8) as $item)
                        <li class="feed-item">
                            <span><a class="mono" href="{{ route('admin.invoices.show', $item->invoice) }}">{{ $item->invoice->displayNumber() }}</a> · <span class="num">{{ money($item->amount, $domain->currency) }}</span></span>
                            <x-status :value="$item->invoice->status" />
                        </li>
                    @empty
                        <li class="muted">{{ __('No invoices.') }}</li>
                    @endforelse
                </ul>
            </section>
        </div>
    </div>
</x-layouts.admin>
