<x-layouts.admin :title="__('Search engines')">
    <x-settings-page>

        <div style="display:grid;gap:14px;max-width:960px;grid-template-columns:minmax(0,1fr)">
            <form method="POST" action="{{ route('admin.settings.seo.update') }}" enctype="multipart/form-data" style="display:grid;gap:14px;grid-template-columns:minmax(0,1fr)">
                @csrf
                @method('PUT')

                <section class="card" style="display:grid;gap:1rem">
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Your home page on Google') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('The name and text people see in search results before they visit. Products and groups have their own, on their edit pages.') }}</p>
                    </div>
                    <x-search-appearance :title="setting('seo.home_title')" :description="setting('seo.home_description')" :default-title="setting('company.name')"
                        :default-description="\App\Seo\SeoText::groupsDescription()" :url="$siteUrl" title-name="home_title" description-name="home_description" />
                </section>

                <section class="card" style="display:grid;gap:1rem">
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('What search engines are told') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('The client area, cart, checkout and sign-in pages are always hidden from search engines, and so is the admin area.') }}</p>
                    </div>
                    <x-checkbox name="visible" :label="__('Let search engines show this site')" :checked="setting('seo.visible')" :help="__('Turn this off for a test site. Search engines are then asked to stay away from every page.')" />
                    <x-checkbox name="sitemap" :label="__('Make sitemap.xml and list it in robots.txt')" :checked="setting('seo.sitemap')" :help="__('A list of your store pages for search engines. It updates by itself when you add products.')" />
                    <x-checkbox name="structured_data" :label="__('Show prices and company details on Google')" :checked="setting('seo.structured_data')" :help="__('Adds product, price, stock, company and breadcrumb details that Google can show in its results.')" />
                    <x-checkbox name="language_links" :label="__('Link the language versions of each page')" :checked="setting('seo.language_links')" :help="trans_choice('Search engines then show people the page in their own language. :count language is on.|Search engines then show people the page in their own language. :count languages are on.', count(\App\Support\Locales::enabled()))" />
                    <div class="form-grid">
                        <x-input name="title_pattern" :label="__('Page title pattern')" :value="setting('seo.title_pattern')" :help="__('For pages without a title of their own. {page} is the page name and {company} your company name.')" />
                    </div>
                </section>

                <section class="card" style="display:grid;gap:1rem">
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Share image') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('The picture WhatsApp, Facebook, X and LinkedIn show when someone shares a link to your store. Google may also use it.') }}</p>
                    </div>
                    <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                        <div class="share-preview" @if ($shareImage) style="background-image:url('{{ $shareImage }}')" role="img" aria-label="{{ __('Your share image') }}" @endif>@unless ($shareImage)1200 × 630 @endunless</div>
                        <div class="field" style="flex:1 1 220px;min-width:0">
                            <label for="f-share_image">{{ $shareImage ? __('Replace the image') : __('Upload an image') }}</label>
                            <input id="f-share_image" class="input" style="max-width:100%" type="file" name="share_image" accept="image/jpeg,image/png,image/webp" @error('share_image') aria-invalid="true" @enderror>
                            <p class="help">{{ __('JPG, PNG or WebP up to 2 MB. 1200 × 630 pixels works best: your logo and a short line about your company.') }}@if ($shareImageSize) {{ __('Now: :width × :height.', ['width' => $shareImageSize[0], 'height' => $shareImageSize[1]]) }}@endif</p>
                            @error('share_image')<p class="error">{{ $message }}</p>@enderror
                        </div>
                    </div>
                </section>

                <section class="card" style="display:grid;gap:1rem">
                    <div>
                        <h2 style="font-size:1.05rem">{{ __('Google and Bing') }}</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Add your site to Google Search Console and Bing Webmaster Tools to see how people find you. Paste the code or the whole tag they give you.') }}</p>
                    </div>
                    <div class="form-grid">
                        <x-input name="google_code" :label="__('Google Search Console code')" :value="setting('seo.google_code')" autocomplete="off" spellcheck="false" :help="__('Search Console → Add property → URL prefix → HTML tag.')" />
                        <x-input name="bing_code" :label="__('Bing Webmaster code')" :value="setting('seo.bing_code')" autocomplete="off" spellcheck="false" :help="__('Bing Webmaster Tools → Add a site → HTML meta tag.')" />
                    </div>
                    @if (setting('seo.sitemap') && setting('seo.visible'))
                        <p class="muted" style="margin:0;font-size:.88rem">{{ __('Then send them your sitemap address:') }} <a class="mono" style="overflow-wrap:anywhere" href="{{ route('seo.sitemap') }}" target="_blank" rel="noopener">{{ \App\Seo\SiteAddress::url('sitemap.xml') }}</a></p>
                    @endif
                </section>

                <section class="card" style="display:grid;gap:1rem">
                    <div>
                        <h2 style="font-size:1.05rem">robots.txt</h2>
                        <p class="muted" style="margin:.3rem 0 0;font-size:.88rem">{{ __('Tells search engines which parts of the site to leave alone. Your admin address is never listed, so it stays hidden.') }}</p>
                    </div>
                    <pre class="mono" style="margin:0;padding:12px 14px;border:1px solid var(--nb-line);border-radius:6px;background:var(--nb-surface-2);white-space:pre-wrap;overflow-wrap:anywhere;font-size:.82rem" dir="ltr">{{ $robots }}</pre>
                    <x-textarea name="robots_extra" :label="__('Your own lines')" :value="setting('seo.robots_extra')" rows="3" :help="__('Added before the sitemap line. For example: Disallow: /old-page')" />
                </section>

                <div class="form-actions">
                    <button class="btn btn-primary" type="submit">{{ __('Save settings') }}</button>
                </div>
            </form>

            @if ($shareImage)
                <form method="POST" action="{{ route('admin.settings.seo.image.destroy') }}" data-confirm="{{ __('Remove the share image?') }}">
                    @csrf
                    @method('DELETE')
                    <button class="btn btn-danger btn-sm" type="submit">{{ __('Remove the share image') }}</button>
                </form>
            @endif

            <section class="card card-flush">
                <div class="card-header"><h2>{{ __('Old addresses that forward') }}</h2></div>
                @if ($redirects->isEmpty())
                    <p class="muted" style="margin:0;padding:0 1.1rem 1.1rem;font-size:.88rem">{{ __('When you change the web address of a product or group, its old address sends visitors and search engines to the new one. They show up here.') }}</p>
                @else
                    <div class="table-wrap"><table class="table">
                        <thead><tr><th>{{ __('Old address') }}</th><th>{{ __('Goes to') }}</th><th class="num">{{ __('Visits') }}</th><th aria-label="{{ __('Actions') }}"></th></tr></thead>
                        <tbody>
                        @foreach ($redirects as $redirect)
                            <tr>
                                <td class="mono" dir="ltr">/{{ $redirect->from_path }}</td>
                                <td class="mono" dir="ltr"><a href="{{ url($redirect->to_path) }}" target="_blank" rel="noopener">/{{ $redirect->to_path }}</a></td>
                                <td class="num">{{ number_format($redirect->hits) }}</td>
                                <td class="end">
                                    <form method="POST" action="{{ route('admin.settings.seo.redirects.destroy', $redirect) }}" data-confirm="{{ __('Stop forwarding this old address?') }}">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn-ghost btn-sm" type="submit">{{ __('Remove') }}</button>
                                    </form>
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
