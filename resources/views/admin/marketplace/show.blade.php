<x-layouts.admin :title="$listing->name">
    @php
        $install = $listing->install;
        $canPreview = in_array($listing->type, [\App\Marketplace\PackageType::Theme, \App\Marketplace\PackageType::OrderForm], true) && $listing->isInstalled();
        $limits = $listing->type === \App\Marketplace\PackageType::Theme || $listing->type === \App\Marketplace\PackageType::OrderForm ? \App\Marketplace\Permissions::limits($listing->permissions) : [];
    @endphp

    <div class="page-head">
        <div style="display:flex;gap:16px;align-items:center">
            @include('admin.marketplace.partials.icon', ['listing' => $listing, 'size' => 'lg'])
            <div>
                <p class="eyebrow"><a href="{{ route('admin.marketplace.index') }}">{{ __('Marketplace') }}</a> / <a href="{{ route('admin.marketplace.index', ['tab' => $listing->type->tab()]) }}">{{ $listing->type->label() }}</a></p>
                <h1>{{ $listing->name }}</h1>
                <p style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                    @if ($listing->developer)<span>{{ __('by :name', ['name' => $listing->developer]) }}</span>@endif
                    @if ($listing->version)<span>{{ __('Version :version', ['version' => $listing->version]) }}</span>@endif
                    @if ($listing->inCatalog && ! $listing->builtIn)<span style="display:inline-flex;gap:5px;align-items:center;color:var(--nb-accent);font-weight:700"><x-icon name="shield" style="width:15px;height:15px" />{{ __('Reviewed and signed') }}</span>@endif
                </p>
            </div>
        </div>
    </div>

    <div class="grid-2" style="grid-template-columns:minmax(0,1fr) 380px;align-items:start">
        <div style="display:grid;gap:16px">
            @if ($listing->screenshots)
                <section class="market-shots">
                    @foreach ($listing->screenshots as $index => $url)
                        <img src="{{ $url }}" alt="{{ __(':name screenshot :number', ['name' => $listing->name, 'number' => $index + 1]) }}" loading="lazy">
                    @endforeach
                </section>
            @endif

            @if ($listing->description)
                <section class="card" style="display:grid;gap:.6rem">
                    <h2 style="font-size:1.05rem">{{ __('About') }}</h2>
                    <div style="line-height:1.6">{!! nl2br(e($listing->description)) !!}</div>
                </section>
            @endif

            @if ($listing->permissions || $limits)
                <section class="card" style="display:grid;gap:.8rem">
                    <h2 style="font-size:1.05rem">{{ __('What it can do on your site') }}</h2>
                    @foreach ($listing->permissions as $code)
                        @php $permission = \App\Marketplace\Permissions::describe($code); @endphp
                        <div class="market-perm"><span class="dot"><x-icon name="check" /></span><span><b>{{ $permission['title'] }}</b>@if ($permission['text'])<br><span class="muted">{{ $permission['text'] }}</span>@endif</span></div>
                    @endforeach
                    @foreach ($limits as $limit)
                        <div class="market-perm"><span class="dot no"><x-icon name="x" /></span><span><b>{{ $limit['title'] }}</b><br><span class="muted">{{ $limit['text'] }}</span></span></div>
                    @endforeach
                </section>
            @endif
        </div>

        <aside style="display:grid;gap:14px">
            <section class="card" style="display:grid;gap:.9rem">
                @if ($listing->builtIn)
                    <p style="margin:0"><b>{{ __('Built in') }}</b><br><span class="muted">{{ __('Comes with Nuvabill and updates with it.') }}</span></p>
                @elseif (! $listing->isInstalled())
                    <div style="display:flex;align-items:baseline;gap:10px">
                        <span style="font-size:2rem;font-weight:800">{{ $listing->priceLabel() }}</span>
                        @unless ($listing->isFree())<span class="muted">{{ __('one time, one site') }}</span>@endunless
                    </div>
                    @unless ($listing->isFree())
                        <p class="muted" style="margin:0">{{ $listing->updatePrice ? __('Includes 1 year of updates. After that, updates are :price a year. It keeps working if you stop.', ['price' => money($listing->updatePrice, $listing->currency)]) : __('Includes updates.') }}</p>
                    @endunless
                @endif

                @if (! $listing->compatible && ! $listing->isInstalled())
                    <div class="flash" data-tone="warn"><span>{{ __('Needs Nuvabill :version. Update Nuvabill first.', ['version' => ltrim($listing->requires, '>=')]) }}</span></div>
                @elseif (! $listing->isInstalled())
                    <form method="POST" action="{{ route('admin.marketplace.install', $listing->slug) }}" style="display:grid;gap:.7rem">
                        @csrf
                        @unless ($listing->isFree())
                            <x-input name="license_key" :label="__('License key')" required class="mono" placeholder="NVB-XXXX-XXXX-XXXX" autocomplete="off" spellcheck="false" />
                        @endunless
                        <button class="btn btn-primary" type="submit"><x-icon name="download" />{{ __('Install') }}</button>
                    </form>
                    @unless ($listing->isFree())
                        <div class="muted" style="display:flex;align-items:center;gap:10px;font-size:.85rem"><span style="height:1px;flex:1;background:var(--nb-line)"></span>{{ __('No key yet?') }}<span style="height:1px;flex:1;background:var(--nb-line)"></span></div>
                        @if ($listing->storeUrl)<a class="btn" href="{{ $listing->storeUrl }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Buy on :site', ['site' => parse_url(config('nuvabill.marketplace.url'), PHP_URL_HOST)]) }}</a>@endif
                    @endunless
                @else
                    <div class="summary-row" style="font-size:.95rem"><span>{{ __('Installed version') }}</span><b>{{ $listing->installedVersion }}</b></div>

                    @if ($listing->hasUpdate())
                        <form method="POST" action="{{ route('admin.marketplace.install', $listing->slug) }}">
                            @csrf
                            <button class="btn btn-primary btn-block" type="submit"><x-icon name="refresh" />{{ __('Update to :version', ['version' => $listing->version]) }}</button>
                        </form>
                    @endif

                    @if ($canPreview)
                        @if ($isActive)
                            <div class="flash"><span>{{ __('In use for everyone.') }}</span></div>
                            @unless ($listing->builtIn)
                                <form method="POST" action="{{ route('admin.marketplace.deactivate', $listing->slug) }}">@csrf<button class="btn btn-block" type="submit">{{ __('Switch back to the standard one') }}</button></form>
                            @endunless
                        @else
                            <form method="POST" action="{{ route('admin.marketplace.activate', $listing->slug) }}">@csrf<button class="btn btn-primary btn-block" type="submit">{{ __('Use it for everyone') }}</button></form>
                            <a class="btn btn-block" href="{{ route('preview.start', [$listing->type === \App\Marketplace\PackageType::Theme ? 'theme' : 'orderform', $listing->slug]) }}" target="_blank" rel="noopener"><x-icon name="external" />{{ __('Preview it first') }}</a>
                        @endif
                    @elseif ($settingsUrl)
                        <a class="btn btn-primary btn-block" href="{{ $settingsUrl }}"><x-icon name="settings" />{{ $isActive ? __('Settings') : __('Set it up and switch it on') }}</a>
                    @endif
                @endif

                @if ($steps)
                    <ul class="market-steps">
                        @foreach ($steps as $step)
                            <li><x-icon name="check" />{{ $step }}</li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($install && ! $listing->isFree())
                <section class="card" style="display:grid;gap:.7rem">
                    <h2 style="font-size:1rem">{{ __('License') }}</h2>
                    @if ($install->license_status === \App\Models\MarketplaceInstall::LICENSE_VALID)
                        <x-pill tone="good" style="justify-self:start">{{ __('Valid for this site') }}</x-pill>
                    @elseif ($install->hasInvalidLicense())
                        <div class="flash" data-tone="warn"><span>{{ $install->license_message ?: __('This license key is not valid for this site.') }}</span></div>
                    @endif
                    <form method="POST" action="{{ route('admin.marketplace.license', $listing->slug) }}" style="display:grid;gap:.6rem">
                        @csrf
                        @method('PUT')
                        <x-input name="license_key" :label="$install->license_key ? __('New license key') : __('License key')" :placeholder="$install->license_key ? \Illuminate\Support\Str::mask($install->license_key, '•', 4, -4) : 'NVB-XXXX-XXXX-XXXX'" class="mono" autocomplete="off" spellcheck="false" required />
                        <button class="btn" type="submit">{{ __('Save and check') }}</button>
                    </form>
                </section>
            @endif

            <section class="card">
                <dl class="dl">
                    <dt>{{ __('Type') }}</dt><dd>{{ $listing->type->label() }}</dd>
                    @if ($listing->requires)<dt>{{ __('Needs') }}</dt><dd>{{ __('Nuvabill :version', ['version' => str_replace('>=', '', $listing->requires).'+']) }}</dd>@endif
                    @if ($listing->category)<dt>{{ __('Category') }}</dt><dd>{{ $listing->category }}</dd>@endif
                    @if ($listing->demoUrl)<dt>{{ __('Demo') }}</dt><dd><a href="{{ $listing->demoUrl }}" target="_blank" rel="noopener">{{ __('Open the live demo') }}</a></dd>@endif
                </dl>
            </section>

            @if ($install)
                <form method="POST" action="{{ route('admin.marketplace.destroy', $listing->slug) }}" onsubmit="return confirm(@js(__('Remove :name from this site?', ['name' => $listing->name])))">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-ghost" type="submit" style="color:var(--nb-crit)"><x-icon name="trash" />{{ __('Remove from this site') }}</button>
                </form>
            @endif
        </aside>
    </div>
</x-layouts.admin>
