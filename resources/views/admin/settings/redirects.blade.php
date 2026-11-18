<x-layouts.admin :title="__('Redirects')">
    <x-settings-page>
        <x-slot:actions>
            <a class="btn" href="{{ route('admin.settings.seo.edit') }}"><x-icon name="chevron-left" />{{ __('Search engines') }}</a>
        </x-slot:actions>

        @php
            // Each form on this page sends the same field names, so old input only fills the form it came from.
            $oldFor = fn (string $form, string $field, mixed $default) => old('_form') === $form ? old($field, $default) : $default;
            $kinds = [301 => __('Moved for good (301)'), 302 => __('Moved for now (302)')];
            $sources = ['auto' => __('Automatic'), 'manual' => __('Yours'), 'whmcs' => 'WHMCS', 'addon' => __('Website')];
            $siteHidden = ! setting('seo.visible');
        @endphp

        <div style="display:grid;gap:14px;max-width:960px;grid-template-columns:minmax(0,1fr)">
            <form method="POST" action="{{ route('admin.settings.seo.redirects.store') }}" class="card" style="display:grid;gap:1rem">
                @csrf
                <input type="hidden" name="_form" value="new">
                <div>
                    <h2 style="font-size:1.05rem">{{ __('Add a redirect') }}</h2>
                    <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('A redirect sends visitors and search engines from an old address to a new one. It only works when the old address shows "page not found" here, so it never hides a page that exists.') }}</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="r-new-from">{{ __('Old address') }} <span aria-hidden="true">*</span></label>
                        <div style="display:flex;align-items:center;gap:6px;min-width:0" dir="ltr">
                            <span class="mono faint" style="font-size:.85rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:50%">{{ $siteUrl }}</span>
                            <input id="r-new-from" class="input" name="from" required maxlength="300" spellcheck="false" autocomplete="off" placeholder="clientarea.php"
                                value="{{ $oldFor('new', 'from', $prefill) }}" @error('from') aria-invalid="true" aria-describedby="r-new-from-error" @enderror>
                        </div>
                        @error('from')<p class="error" id="r-new-from-error">{{ $message }}</p>@else<p class="help">{{ __('Only the address, without the part after ?') }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="r-new-to">{{ __('Goes to') }} <span aria-hidden="true">*</span></label>
                        <input id="r-new-to" class="input" name="to" required maxlength="300" spellcheck="false" autocomplete="off" dir="ltr" placeholder="/login"
                            value="{{ $oldFor('new', 'to', '') }}" @error('to') aria-invalid="true" aria-describedby="r-new-to-error" @enderror>
                        @error('to')<p class="error" id="r-new-to-error">{{ $message }}</p>@else<p class="help">{{ __('A page on this site, such as /store or /knowledgebase.') }}</p>@enderror
                    </div>
                    <div class="field">
                        <label for="r-new-status">{{ __('Kind') }}</label>
                        <select id="r-new-status" class="select" name="status">
                            @foreach ($kinds as $code => $label)
                                <option value="{{ $code }}" @selected((string) $oldFor('new', 'status', 301) === (string) $code)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="help">{{ __('Use "Moved for good" unless the old address will come back.') }}</p>
                    </div>
                    <div class="field">
                        <input type="hidden" name="keep_query" value="0">
                        <label class="check" for="r-new-keep">
                            <input id="r-new-keep" type="checkbox" name="keep_query" value="1" @checked((bool) $oldFor('new', 'keep_query', false))>
                            <span>{{ __('Keep the part after ?') }}<br><span class="help">{{ __('For example ?cycle=annually goes along to the new address.') }}</span></span>
                        </label>
                    </div>
                </div>
                <div><button class="btn btn-primary" type="submit"><x-icon name="plus" />{{ __('Add the redirect') }}</button></div>
            </form>

            <section class="card" style="display:grid;gap:1rem">
                <div>
                    <h2 style="font-size:1.05rem">{{ __('Old WHMCS addresses') }}</h2>
                    <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Moving from WHMCS? People and search engines still open its old addresses. Add them, and each one goes to the matching page here. Old ?rp= links are forwarded too.') }}</p>
                </div>
                <ul style="margin:0;padding:0;list-style:none;display:grid;gap:4px;font-size:.85rem;grid-template-columns:repeat(auto-fill,minmax(240px,1fr))" dir="ltr">
                    @foreach ($whmcs as $old => $new)
                        <li class="mono" style="overflow-wrap:anywhere">/{{ $old }} → /{{ $new }}</li>
                    @endforeach
                </ul>
                <p class="muted" style="margin:0;font-size:.88rem">{{ __('On Nginx, old .php addresses reach Nuvabill only when your server sends them to index.php.') }} <a href="{{ $nginxGuide }}" target="_blank" rel="noopener">{{ __('See the guide.') }}</a></p>
                <form method="POST" action="{{ route('admin.settings.seo.redirects.whmcs') }}" data-confirm="{{ __('Add the old WHMCS addresses as redirects? Redirects you already have are kept.') }}">
                    @csrf
                    <button class="btn" type="submit">{{ __('Add the WHMCS addresses') }}</button>
                </form>
            </section>

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Redirects') }}</h2></div>
                @if ($redirects->isEmpty())
                    <p class="muted" style="margin:0;padding:0 1.1rem 1.1rem;font-size:.88rem">{{ __('No redirects yet. When you change the web address of a product or group, its old address forwards by itself and shows up here.') }}</p>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Old address') }}</th><th>{{ __('Goes to') }}</th><th>{{ __('Kind') }}</th><th>{{ __('Source') }}</th><th class="num">{{ __('Visits') }}</th><th>{{ __('Last visit') }}</th><th aria-label="{{ __('Actions') }}"></th></tr></thead>
                        <tbody>
                        @foreach ($redirects as $redirect)
                            @php
                                $form = (string) $redirect->id;
                                $bag = $errors->getBag('redirect'.$redirect->id);
                                $status = in_array($redirect->status, \App\Models\SeoRedirect::STATUSES, true) ? $redirect->status : 301;
                            @endphp
                            <tr>
                                <td class="mono" dir="ltr" style="overflow-wrap:anywhere">/{{ $redirect->from_path }}
                                    @if ($blocked[$redirect->id] ?? null)
                                        <br><x-pill tone="warn" title="{{ $blocked[$redirect->id] }}">{{ __('robots.txt blocks it') }}</x-pill> <span class="faint" style="font-size:.8rem">{{ $blocked[$redirect->id] }}</span>
                                    @endif
                                    @if ($siteHidden)
                                        <br><x-pill tone="warn">{{ __('Site hidden from search engines') }}</x-pill>
                                    @endif
                                </td>
                                <td class="mono" dir="ltr" style="overflow-wrap:anywhere"><a href="{{ $redirect->targetUrl() }}" target="_blank" rel="noopener">/{{ $redirect->to_path }}</a></td>
                                <td>{{ $status }}</td>
                                <td><x-pill :tone="$redirect->source === 'manual' ? 'info' : null">{{ $sources[$redirect->source ?? 'auto'] ?? __('Automatic') }}</x-pill></td>
                                <td class="num">{{ number_format($redirect->hits) }}</td>
                                <td>@if ($redirect->last_hit_at)<time datetime="{{ $redirect->last_hit_at->toIso8601String() }}">{{ $redirect->last_hit_at->diffForHumans(short: true) }}</time>@else<span class="faint">{{ __('Never') }}</span>@endif</td>
                                <td class="end">
                                    <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                                        <details @if ($bag->any()) open @endif style="text-align:start">
                                            <summary class="btn btn-ghost btn-sm">{{ __('Edit') }}</summary>
                                            <form method="POST" action="{{ route('admin.settings.seo.redirects.update', $redirect) }}" style="display:grid;gap:.6rem;margin-top:.6rem;min-width:240px">
                                                @csrf
                                                @method('PUT')
                                                <input type="hidden" name="_form" value="{{ $form }}">
                                                <div class="field">
                                                    <label for="r-{{ $form }}-from">{{ __('Old address') }}</label>
                                                    <input id="r-{{ $form }}-from" class="input" name="from" required maxlength="300" dir="ltr" spellcheck="false" value="{{ $oldFor($form, 'from', '/'.$redirect->from_path) }}" @if ($bag->has('from')) aria-invalid="true" @endif>
                                                    @if ($bag->has('from'))<p class="error">{{ $bag->first('from') }}</p>@endif
                                                </div>
                                                <div class="field">
                                                    <label for="r-{{ $form }}-to">{{ __('Goes to') }}</label>
                                                    <input id="r-{{ $form }}-to" class="input" name="to" required maxlength="300" dir="ltr" spellcheck="false" value="{{ $oldFor($form, 'to', '/'.$redirect->to_path) }}" @if ($bag->has('to')) aria-invalid="true" @endif>
                                                    @if ($bag->has('to'))<p class="error">{{ $bag->first('to') }}</p>@endif
                                                </div>
                                                <div class="field">
                                                    <label for="r-{{ $form }}-status">{{ __('Kind') }}</label>
                                                    <select id="r-{{ $form }}-status" class="select" name="status">
                                                        @foreach ($kinds as $code => $label)
                                                            <option value="{{ $code }}" @selected((string) $oldFor($form, 'status', $status) === (string) $code)>{{ $label }}</option>
                                                        @endforeach
                                                    </select>
                                                </div>
                                                <input type="hidden" name="keep_query" value="0">
                                                <label class="check" for="r-{{ $form }}-keep">
                                                    <input id="r-{{ $form }}-keep" type="checkbox" name="keep_query" value="1" @checked((bool) $oldFor($form, 'keep_query', $redirect->keep_query ?? true))>
                                                    <span>{{ __('Keep the part after ?') }}</span>
                                                </label>
                                                <div><button class="btn btn-primary btn-sm" type="submit">{{ __('Save') }}</button></div>
                                            </form>
                                        </details>
                                        <form method="POST" action="{{ route('admin.settings.seo.redirects.destroy', $redirect) }}" data-confirm="{{ __('Stop forwarding this old address?') }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-sm" type="submit">{{ __('Remove') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    {{ $redirects->links() }}
                @endif
            </section>

            <section class="card card-flush">
                <div class="card-header" style="flex-wrap:wrap;gap:8px">
                    <h2>{{ __('Addresses people could not open') }}</h2>
                    @if ($missing->isNotEmpty())
                        <form method="POST" action="{{ route('admin.settings.seo.missing.clear') }}" data-confirm="{{ __('Clear the list of addresses people could not open?') }}">
                            @csrf
                            @method('DELETE')
                            <button class="btn btn-ghost btn-sm" type="submit">{{ __('Clear the list') }}</button>
                        </form>
                    @endif
                </div>
                <p class="muted" style="margin:0;padding:0 1.1rem 1.1rem;font-size:.88rem">{{ __('Old links that showed "page not found", most visited first. Only the address is kept, nothing about the visitor. Pictures, scripts and the admin area are left out.') }}</p>
                @if ($missing->isNotEmpty())
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Address') }}</th><th class="num">{{ __('Visits') }}</th><th>{{ __('Last visit') }}</th><th aria-label="{{ __('Actions') }}"></th></tr></thead>
                        <tbody>
                        @foreach ($missing as $page)
                            <tr>
                                <td class="mono" dir="ltr" style="overflow-wrap:anywhere">/{{ $page->path }}</td>
                                <td class="num">{{ number_format($page->hits) }}</td>
                                <td>@if ($page->last_seen_at)<time datetime="{{ $page->last_seen_at->toIso8601String() }}">{{ $page->last_seen_at->diffForHumans(short: true) }}</time>@endif</td>
                                <td class="end">
                                    <div style="display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap">
                                        <a class="btn btn-sm" href="{{ route('admin.settings.seo.redirects.index', ['from' => $page->path]) }}#r-new-from">{{ __('Make a redirect') }}</a>
                                        <form method="POST" action="{{ route('admin.settings.seo.missing.destroy', $page) }}">
                                            @csrf
                                            @method('DELETE')
                                            <button class="btn btn-ghost btn-sm" type="submit">{{ __('Ignore') }}</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </section>
        </div>
    </x-settings-page>
</x-layouts.admin>
