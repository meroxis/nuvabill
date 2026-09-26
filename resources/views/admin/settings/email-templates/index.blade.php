<x-layouts.admin :title="__('Email templates')">
    <div class="page-head"><div><h1>{{ __('Settings') }}</h1></div></div>
    @include('admin.settings.nav')

    <section class="card card-flush">
        <div class="card-header"><h2>{{ __('Email templates') }}</h2><span class="faint" style="font-size:.85rem">{{ __('Change the words of every email Nuvabill sends.') }}</span></div>
        <div class="table-wrap"><table class="table">
            <thead><tr><th>{{ __('Email') }}</th><th>{{ __('Subject') }}</th><th>{{ __('Status') }}</th></tr></thead>
            <tbody>
            @foreach ($templates as $template)
                <tr>
                    <td><a class="row-link" href="{{ route('admin.settings.email-templates.edit', $template) }}">{{ $template->name }}</a><div class="faint mono" style="font-size:.76rem">{{ $template->key }}</div></td>
                    <td class="muted">{{ $template->subject }}</td>
                    <td>@if ($template->is_active)<x-pill tone="good">{{ __('Sending') }}</x-pill>@else<x-pill>{{ __('Off') }}</x-pill>@endif</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    </section>
</x-layouts.admin>
