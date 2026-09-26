@extends('theme::layouts.app')

@section('title', __('Domains'))

@section('content')
    <div class="page-title">
        <div><h1>{{ __('Your domains') }}</h1></div>
        <a class="btn btn-primary" href="{{ route('store.domains') }}"><x-icon name="search" />{{ __('Find a domain') }}</a>
    </div>

    <section class="card card-flush">
        @if ($domains->isEmpty())
            <div class="empty"><strong>{{ __('No domains yet') }}</strong>{{ __('Domains you register or transfer to us appear here.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Domain') }}</th><th>{{ __('Expires') }}</th><th>{{ __('Auto-renew') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($domains as $domain)
                    <tr>
                        <td><a class="row-link" href="{{ route('client.domains.show', $domain) }}">{{ $domain->name }}</a></td>
                        <td style="white-space:nowrap">{{ $domain->expires_at?->format('d M Y') ?? '—' }}</td>
                        <td>{{ $domain->auto_renew ? __('On') : __('Off') }}</td>
                        <td><x-status :value="$domain->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
            {{ $domains->links() }}
        @endif
    </section>
@endsection
