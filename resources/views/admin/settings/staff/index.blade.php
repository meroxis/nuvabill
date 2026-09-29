<x-layouts.admin :title="__('Staff')">
    <x-settings-page>
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('admin.settings.staff.create') }}"><x-icon name="plus" />{{ __('Add staff') }}</a>
        </x-slot:actions>

        <section class="card card-flush">
            <div class="table-wrap"><table class="table">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Role') }}</th><th>{{ __('Two-factor') }}</th><th>{{ __('Last sign in') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($staff as $member)
                    <tr>
                        <td><a class="row-link" href="{{ route('admin.settings.staff.edit', $member) }}">{{ $member->name }}</a><div class="faint" style="font-size:.8rem">{{ $member->email }}</div></td>
                        <td>{{ $member->role?->name ?? '—' }}</td>
                        <td>@if ($member->hasTwoFactorEnabled())<x-pill tone="good">{{ __('On') }}</x-pill>@else<x-pill tone="warn">{{ __('Off') }}</x-pill>@endif</td>
                        <td style="white-space:nowrap">{{ $member->last_login_at?->diffForHumans() ?? __('Never') }}</td>
                        <td>@if ($member->is_active)<x-pill tone="good">{{ __('Active') }}</x-pill>@else<x-pill>{{ __('Off') }}</x-pill>@endif</td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        </section>
    </x-settings-page>
</x-layouts.admin>
