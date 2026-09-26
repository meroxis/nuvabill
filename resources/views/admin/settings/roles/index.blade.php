<x-layouts.admin :title="__('Roles')">
    <div class="page-head">
        <div><h1>{{ __('Settings') }}</h1></div>
        <a class="btn btn-primary" href="{{ route('admin.settings.roles.create') }}"><x-icon name="plus" />{{ __('New role') }}</a>
    </div>
    @include('admin.settings.nav')

    <section class="card card-flush">
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Role') }}</th><th>{{ __('Can do') }}</th><th class="end">{{ __('Staff') }}</th></tr></thead>
            <tbody>
            @foreach ($roles as $role)
                <tr>
                    <td><a class="row-link" href="{{ route('admin.settings.roles.edit', $role) }}">{{ $role->name }}</a></td>
                    <td class="muted" style="font-size:.85rem">{{ $role->isOwner() ? __('Everything') : trans_choice(':count permission|:count permissions', count($role->permissions), ['count' => count($role->permissions)]) }}</td>
                    <td class="end num">{{ $role->admins_count }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</x-layouts.admin>
