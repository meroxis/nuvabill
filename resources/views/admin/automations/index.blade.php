<x-layouts.admin :title="__('Automations')">
    <div class="page-head">
        <div>
            <p class="eyebrow">{{ __('Setup') }}</p>
            <h1 style="margin-top:.2rem">{{ __('Automations') }}</h1>
            <p>{{ __('When something happens, Nuvabill does the next step for you. Every run is logged.') }}</p>
        </div>
        <div class="form-actions">
            <a class="btn" href="{{ route('admin.automations.runs') }}">{{ __('All runs') }}</a>
            <a class="btn btn-primary" href="{{ route('admin.automations.create') }}"><x-icon name="plus" />{{ __('New automation') }}</a>
        </div>
    </div>

    <div class="automation-layout">
        <div style="display:grid;gap:14px;align-content:start;min-width:0">
            <section class="card card-flush">
                @if ($automations->isEmpty())
                    <div class="empty">
                        <strong>{{ __('No automations yet') }}</strong>
                        {{ __('Start from a template on the right, or make your own.') }}
                    </div>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Name') }}</th><th>{{ __('When') }}</th><th>{{ __('Does') }}</th><th class="num">{{ __('Last 30 days') }}</th><th aria-label="{{ __('Actions') }}"></th></tr></thead>
                        <tbody>
                        @foreach ($automations as $automation)
                            <tr>
                                <td>
                                    <a href="{{ route('admin.automations.edit', $automation) }}" style="font-weight:700">{{ $automation->name }}</a>
                                    <div>@if ($automation->is_active)<x-pill tone="good">{{ __('On') }}</x-pill>@else<x-pill>{{ __('Off') }}</x-pill>@endif</div>
                                </td>
                                <td class="muted" style="min-width:180px">{{ $registry->describeTrigger($automation) }}</td>
                                <td style="min-width:220px">{{ \Illuminate\Support\Str::limit($registry->summarise($automation), 140) }}</td>
                                <td class="num"><a href="{{ route('admin.automations.runs', ['automation' => $automation->id]) }}">{{ trans_choice(':count run|:count runs', $automation->recent_runs, ['count' => $automation->recent_runs]) }}</a></td>
                                <td class="end">
                                    <form method="POST" action="{{ route('admin.automations.toggle', $automation) }}">
                                        @csrf
                                        <button class="btn btn-sm" type="submit"><x-icon name="power" />{{ $automation->is_active ? __('Switch off') : __('Switch on') }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Recent runs') }}</h2><a href="{{ route('admin.automations.runs') }}" style="font-size:.88rem">{{ __('All runs') }}</a></div>
                @forelse ($recentRuns as $run)
                    @include('admin.automations.partials.run', ['run' => $run])
                @empty
                    <p class="muted" style="margin:0;padding:0 1.1rem 1.1rem;font-size:.9rem">{{ __('Runs show up here as soon as an automation starts.') }}</p>
                @endforelse
            </section>
        </div>

        <section class="card" style="display:grid;gap:.7rem;align-content:start">
            <div>
                <h2 style="font-size:1.05rem">{{ __('Start from a template') }}</h2>
                <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Change anything before you switch it on.') }}</p>
            </div>
            @foreach ($templates as $key => $template)
                <a class="automation-template" href="{{ route('admin.automations.create', ['template' => $key]) }}">
                    <b>{{ __($template['name']) }}</b>
                    <span class="muted">{{ __($template['description']) }}</span>
                </a>
            @endforeach
        </section>
    </div>
</x-layouts.admin>
