<x-layouts.admin :title="__('Servers')">
    <div class="page-head">
        <div>
            <h1>{{ __('Servers') }}</h1>
            <p>{{ __('Control panel servers that hosting accounts are created on.') }}</p>
        </div>
        <a class="btn btn-primary" href="{{ route('admin.servers.create') }}"><x-icon name="plus" />{{ __('Add server') }}</a>
    </div>

    <section class="card card-flush">
        @if ($servers->isEmpty())
            <div class="empty"><strong>{{ __('No servers yet') }}</strong>{{ __('Add your cPanel/WHM server so new orders are set up automatically.') }}</div>
        @else
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Server') }}</th><th>{{ __('Module') }}</th><th class="end">{{ __('Accounts') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.servers.edit', $server) }}">{{ $server->name }}</a><div class="faint mono" style="font-size:.8rem">{{ $server->hostname }}</div></td>
                        <td>{{ $server->module }}</td>
                        <td class="end num">{{ $server->accounts_count }}{{ $server->max_accounts ? ' / '.$server->max_accounts : '' }}</td>
                        <td>@if ($server->is_active)<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill>{{ __('Off') }}</x-pill>@endif</td>
                        <td class="end">
                            <form method="POST" action="{{ route('admin.servers.test', $server) }}">@csrf<button class="btn btn-sm" type="submit">{{ __('Test connection') }}</button></form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </section>
</x-layouts.admin>
