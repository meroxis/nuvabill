<x-layouts.admin :title="__('Automation runs')">
    <div class="page-head">
        <div>
            <p class="eyebrow"><a href="{{ route('admin.automations.index') }}">{{ __('Automations') }}</a></p>
            <h1 style="margin-top:.2rem">{{ __('Runs') }}</h1>
            <p>{{ __('Every time an automation starts, what each step did, and runs that wait.') }}</p>
        </div>
    </div>

    <form method="GET" class="filters" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:center">
        <select class="select" name="automation" aria-label="{{ __('Automation') }}" data-autosubmit style="max-width:280px">
            <option value="">{{ __('All automations') }}</option>
            @foreach ($automations as $id => $name)
                <option value="{{ $id }}" @selected((string) request('automation') === (string) $id)>{{ $name }}</option>
            @endforeach
        </select>
        <select class="select" name="status" aria-label="{{ __('Status') }}" data-autosubmit style="max-width:200px">
            <option value="">{{ __('Every status') }}</option>
            <option value="waiting" @selected($status === 'waiting')>{{ __('Waiting') }}</option>
            <option value="done" @selected($status === 'done')>{{ __('Done') }}</option>
            <option value="stopped" @selected($status === 'stopped')>{{ __('Stopped') }}</option>
            <option value="failed" @selected($status === 'failed')>{{ __('Failed') }}</option>
        </select>
        <noscript><button class="btn btn-sm" type="submit">{{ __('Filter') }}</button></noscript>
    </form>

    <section class="card card-flush">
        @forelse ($runs as $run)
            @include('admin.automations.partials.run', ['run' => $run])
        @empty
            <div class="empty"><strong>{{ __('No runs') }}</strong>{{ __('Runs show up here as soon as an automation starts.') }}</div>
        @endforelse
    </section>

    {{ $runs->links() }}
</x-layouts.admin>
