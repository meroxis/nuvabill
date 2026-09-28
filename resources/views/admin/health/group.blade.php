<x-layouts.admin :title="__($group->title())">
    <div class="page-head">
        <div>
            <p style="margin:0 0 .2rem;font-size:.85rem">
                <a class="row-link" href="{{ route(match ($section) { 'database' => 'admin.health.database', 'seo' => 'admin.health.seo', default => 'admin.health.index' }) }}">{{ __('Site health') }}</a>
                <span class="muted"> / {{ match ($section) { 'database' => __('Database'), 'seo' => __('Search engines'), default => __('Security') } }}</span>
            </p>
            <h1>{{ __($group->title()) }}</h1>
            <p>{{ __($group->description()) }}</p>
        </div>
        <form method="POST" action="{{ route('admin.health.run') }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Check now') }}</button></form>
    </div>

    <section class="card card-flush">
        @forelse ($checks as $check)
            @include('admin.health.partials.check', ['check' => $check, 'open' => true])
        @empty
            <div class="empty"><strong>{{ __('Not checked yet') }}</strong>{{ __('Press "Check now" to run the checks.') }}</div>
        @endforelse
    </section>
</x-layouts.admin>
