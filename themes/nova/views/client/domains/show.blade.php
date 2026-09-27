@extends('theme::layouts.app')

@section('title', $domain->name)

@section('content')
    @php
        $status = $domain->status;
        $nameservers = old('nameservers', array_pad($domain->nameserverList(), 4, ''));
    @endphp

    <div class="page-title">
        <div>
            <p class="eyebrow">{{ __('Domain') }}</p>
            <h1 style="margin-top:.3rem;overflow-wrap:anywhere">{{ $domain->name }} <x-status :value="$status" style="vertical-align:middle" /></h1>
        </div>
        @if ($status->isRenewable())
            <form method="POST" action="{{ route('client.domains.renew', $domain) }}">
                @csrf
                <button class="btn btn-primary" type="submit"><x-icon name="refresh" />{{ __('Renew now') }}</button>
            </form>
        @endif
    </div>

    @if ($status === \App\Enums\DomainStatus::Pending)
        <div class="flash" data-tone="info"><span>{{ $domain->isTransfer() ? __('The transfer starts as soon as the invoice is paid.') : __('We register the domain as soon as the invoice is paid.') }}</span></div>
    @elseif ($status === \App\Enums\DomainStatus::PendingTransfer)
        <div class="flash" data-tone="info"><span>{{ __('The transfer is in progress. It usually takes 5 to 7 days. Your current registrar may email you to approve it.') }}</span></div>
    @elseif ($status === \App\Enums\DomainStatus::Expired)
        <div class="flash" data-tone="warn"><span>{{ __('This domain has expired. Renew it soon, or it may be deleted and someone else can register it.') }}</span></div>
    @endif

    <div class="two-col">
        <div style="display:grid;gap:18px">
            <section class="card" style="display:grid;gap:1rem">
                <h2 style="font-size:1.05rem">{{ __('Details') }}</h2>
                <dl class="dl">
                    <dt>{{ __('Registered') }}</dt><dd>{{ $domain->registered_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                    <dt>{{ __('Expires') }}</dt><dd>{{ $domain->expires_at?->translatedFormat('d M Y') ?? '—' }}</dd>
                    <dt>{{ __('Renewal price') }}</dt><dd>{{ money($domain->recurring_amount, $domain->currency) }} · {{ trans_choice(':count year|:count years', $domain->years, ['count' => $domain->years]) }}</dd>
                </dl>
                <form method="POST" action="{{ route('client.domains.auto-renew', $domain) }}" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="auto_renew" value="{{ $domain->auto_renew ? 0 : 1 }}">
                    <span>{{ $domain->auto_renew ? __('Auto-renew is on.') : __('Auto-renew is off.') }}</span>
                    <button class="btn btn-sm" type="submit">{{ $domain->auto_renew ? __('Turn off') : __('Turn on') }}</button>
                </form>
            </section>

            @if (in_array($status, [\App\Enums\DomainStatus::Active, \App\Enums\DomainStatus::Pending], true))
                <form method="POST" action="{{ route('client.domains.nameservers', $domain) }}" class="card" style="display:grid;gap:1rem">
                    @csrf
                    @method('PUT')
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Nameservers') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Nameservers tell the internet where your website and email are. Only change them if you know the new values.') }}</p>
                    </div>
                    @error('nameservers')<div class="flash" data-tone="crit"><span>{{ $message }}</span></div>@enderror
                    <div class="form-grid">
                        @foreach ($nameservers as $index => $nameserver)
                            <x-input :name="'nameservers['.$index.']'" :label="__('Nameserver :number', ['number' => $index + 1])" :value="$nameserver" :required="$index < 2" placeholder="ns{{ $index + 1 }}.example.com" autocomplete="off" spellcheck="false" />
                        @endforeach
                    </div>
                    <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save nameservers') }}</button></div>
                </form>
            @endif
        </div>

        <aside class="card" style="display:grid;gap:.8rem">
            <h2 style="font-size:1.05rem">{{ __('Invoices') }}</h2>
            @forelse ($domain->invoiceItems->sortByDesc('id')->take(6) as $item)
                <div class="summary-row">
                    <a class="mono" href="{{ route('client.invoices.show', $item->invoice) }}">{{ $item->invoice->displayNumber() }}</a>
                    <x-status :value="$item->invoice->status" />
                </div>
            @empty
                <p class="muted" style="margin:0">{{ __('No invoices yet.') }}</p>
            @endforelse
            <a class="btn btn-sm" href="{{ route('client.tickets.create') }}">{{ __('Get help with this domain') }}</a>
        </aside>
    </div>
@endsection
