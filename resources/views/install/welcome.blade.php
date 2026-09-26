<x-layouts.guest :title="__('Install Nuvabill')" :subtitle="__('Step 1 of 3 · Check your server')" :wide="true">
    <p style="margin:0">{{ __('Nuvabill runs billing, automation and support for your hosting business. Setup takes about three minutes.') }}</p>

    <ul class="list-plain" style="gap:6px">
        @foreach ($checks as $check)
            <li style="display:flex;gap:10px;align-items:flex-start;font-size:.9rem">
                @if ($check['ok'])
                    <x-icon name="check" style="width:18px;height:18px;color:var(--nb-good);flex:none;margin-top:2px" />
                    <span>{{ $check['label'] }}</span>
                @else
                    <x-icon name="x" style="width:18px;height:18px;color:var(--nb-crit);flex:none;margin-top:2px" />
                    <span><b>{{ $check['label'] }}</b><br><span class="muted">{{ $check['help'] }}</span></span>
                @endif
            </li>
        @endforeach
    </ul>

    @if ($passes)
        <a class="btn btn-primary btn-block" href="{{ route('install.database') }}">{{ __('Everything is ready. Continue') }}</a>
    @else
        <div class="flash" data-tone="warn"><span>{{ __('Fix the items marked with ✕, then reload this page.') }}</span></div>
        <a class="btn btn-block" href="{{ route('install.welcome') }}">{{ __('Check again') }}</a>
    @endif
</x-layouts.guest>
