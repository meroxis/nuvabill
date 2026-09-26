<x-layouts.admin :title="__('Updates')">
    <div class="page-head">
        <div>
            <h1>{{ __('Updates') }}</h1>
            <p>{{ __('You have Nuvabill :version.', ['version' => $current]) }}
                {{ $lastChecked ? __('Last checked :time.', ['time' => \Illuminate\Support\Carbon::parse($lastChecked)->diffForHumans()]) : __('Never checked.') }}</p>
        </div>
        <form method="POST" action="{{ route('admin.updates.check') }}">@csrf<button class="btn" type="submit"><x-icon name="refresh" />{{ __('Check now') }}</button></form>
    </div>

    @unless ($hasPublicKey)
        <div class="flash" data-tone="warn"><span>{{ __('This copy has no update signing key, so it cannot verify or install updates. Development copies are normal; release downloads include the key.') }}</span></div>
    @endunless

    @if ($pending)
        <div class="flash" data-tone="warn" style="align-items:center">
            <span>{{ __('An update copied its files but did not finish.') }}</span>
            <a class="btn btn-sm btn-primary" href="{{ route('admin.updates.finish') }}" style="margin-inline-start:auto">{{ __('Finish update') }}</a>
        </div>
    @endif

    <div class="grid-2">
        <section class="card" style="display:grid;gap:1rem;align-content:start">
            @if ($release)
                <div style="display:flex;flex-wrap:wrap;align-items:baseline;gap:.6rem">
                    <h2 class="mono" style="font-size:1.4rem">v{{ $release->version }}</h2>
                    @if ($release->isSecurity)<x-pill tone="crit">{{ __('Security') }}</x-pill>@endif
                    @if ($release->isPrerelease)<x-pill tone="info">{{ __('Beta') }}</x-pill>@else<x-pill tone="accent">{{ __('Stable') }}</x-pill>@endif
                    <span class="faint" style="font-size:.85rem">{{ $release->publishedAt ? \Illuminate\Support\Carbon::parse($release->publishedAt)->format('d M Y') : '' }}@if ($release->size) · {{ \Illuminate\Support\Number::fileSize($release->size) }}@endif</span>
                </div>
                <div class="prose-sm" style="max-height:340px;overflow:auto;border:1px solid var(--nb-line);border-radius:8px;padding:.8rem 1rem">
                    {!! \Illuminate\Support\Str::markdown($release->notes ?: __('No release notes.'), ['html_input' => 'escape', 'allow_unsafe_links' => false]) !!}
                </div>
                <form method="POST" action="{{ route('admin.updates.install') }}" data-confirm="{{ __('Install :version now? The site goes into maintenance mode for about a minute.', ['version' => $release->version]) }}">
                    @csrf
                    <button class="btn btn-primary" type="submit"><x-icon name="download" />{{ __('Update now') }}</button>
                    <span class="muted" style="font-size:.85rem;margin-inline-start:.5rem">{{ __('Files and database are backed up first. If anything fails, the old version comes back.') }}</span>
                </form>
            @else
                <div class="empty"><strong>{{ __('You are up to date') }}</strong>{{ __('Nuvabill checks for new versions every day.') }}</div>
            @endif
        </section>

        <form method="POST" action="{{ route('admin.updates.settings') }}" class="card" style="display:grid;gap:1rem;align-content:start">
            @csrf
            @method('PUT')
            <div class="card-header" style="margin:0"><h2>{{ __('Update settings') }}</h2></div>
            <x-select name="channel" :label="__('Channel')" :options="['stable' => __('Stable (recommended)'), 'beta' => __('Beta (test new features early)')]" :value="$settings['updates.channel']" />
            <x-checkbox name="auto_security" :label="__('Install security fixes automatically')" :checked="$settings['updates.auto_security']" />
            <x-checkbox name="auto_all" :label="__('Install every update automatically at 03:00')" :checked="$settings['updates.auto_all']" />
            <div class="form-actions"><button class="btn btn-primary" type="submit">{{ __('Save') }}</button></div>
        </form>
    </div>
</x-layouts.admin>
