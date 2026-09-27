<x-layouts.admin :title="__('Domains')">
    <div class="page-head">
        <div>
            <h1>{{ __('Domains') }}</h1>
            <p>{{ __('Domains your clients registered or transferred to you.') }}</p>
        </div>
        @if (auth('admin')->user()->hasPermission('settings.manage'))
            <a class="btn" href="{{ route('admin.settings.tlds.index') }}"><x-icon name="settings" />{{ __('Prices and registrars') }}</a>
        @endif
    </div>

    <div class="filters">
        <a class="chip" href="{{ route('admin.domains.index') }}" @if (! $status) aria-current="true" @endif>{{ __('All') }}</a>
        @foreach (\App\Enums\DomainStatus::cases() as $case)
            <a class="chip" href="{{ route('admin.domains.index', ['status' => $case->value]) }}" @if ($status === $case) aria-current="true" @endif>{{ $case->label() }}</a>
        @endforeach
        <form method="GET" action="{{ route('admin.domains.index') }}" style="margin-inline-start:auto">
            @if ($status)<input type="hidden" name="status" value="{{ $status->value }}">@endif
            <input class="input" type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Domain name') }}" aria-label="{{ __('Search domains') }}" style="width:220px">
        </form>
    </div>

    <section class="card card-flush">
        @if ($domains->isEmpty())
            <div class="empty"><strong>{{ __('No domains') }}</strong>{{ __('Domains appear here when clients order them.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Domain') }}</th><th>{{ __('Client') }}</th><th>{{ __('Registrar') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($domains as $domain)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.domains.show', $domain) }}">{{ $domain->name }}</a>@if ($domain->isTransfer())<div class="faint" style="font-size:.8rem">{{ __('Transfer') }}</div>@endif</td>
                        <td>{{ $domain->client->name }}</td>
                        <td>{{ $domain->registrar ?: __('By hand') }}</td>
                        <td class="num" style="white-space:nowrap">{{ $domain->expires_at?->translatedFormat('d M Y') ?? '—' }}</td>
                        <td><x-status :value="$domain->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $domains->links() }}
        @endif
    </section>
</x-layouts.admin>
