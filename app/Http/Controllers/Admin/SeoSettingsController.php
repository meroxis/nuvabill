<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SeoRedirect;
use App\Seo\HeadTags;
use App\Seo\RobotsTxt;
use App\Seo\SeoText;
use App\Seo\ShareImage;
use App\Seo\SiteAddress;
use App\Seo\Sitemap;
use App\Support\Activity;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Settings → Search engines: what the store tells Google and Bing, the share picture, the
 * sitemap and robots.txt, and old addresses that forward to new ones.
 */
class SeoSettingsController extends Controller
{
    public function edit(RobotsTxt $robots): View
    {
        return view('admin.settings.seo', [
            'robots' => $robots->content(),
            'shareImage' => ShareImage::url(),
            'shareImageSize' => ShareImage::size(),
            'redirects' => SeoRedirect::query()->latest()->limit(50)->get(),
            'siteUrl' => SiteAddress::url(''),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse
    {
        $data = $request->validate([
            'visible' => ['boolean'],
            'home_title' => ['nullable', 'string', 'max:120'],
            'home_description' => ['nullable', 'string', 'max:320'],
            'title_pattern' => ['nullable', 'string', 'max:120', Rule::when($request->filled('title_pattern'), ['regex:/\{page\}/'])],
            'google_code' => ['nullable', 'string', 'max:300'],
            'bing_code' => ['nullable', 'string', 'max:300'],
            'sitemap' => ['boolean'],
            'structured_data' => ['boolean'],
            'language_links' => ['boolean'],
            'robots_extra' => ['nullable', 'string', 'max:5000'],
            'share_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=600,min_height=315'],
        ], [
            'title_pattern.regex' => __('The pattern needs {page}, where the page name goes.'),
        ]);

        $settings->setMany([
            'seo.visible' => (bool) ($data['visible'] ?? false),
            'seo.home_title' => trim((string) ($data['home_title'] ?? '')),
            'seo.home_description' => trim((string) ($data['home_description'] ?? '')),
            'seo.title_pattern' => trim((string) ($data['title_pattern'] ?? '')) ?: SeoText::DEFAULT_PATTERN,
            'seo.google_code' => HeadTags::verificationCode((string) ($data['google_code'] ?? '')),
            'seo.bing_code' => HeadTags::verificationCode((string) ($data['bing_code'] ?? '')),
            'seo.sitemap' => (bool) ($data['sitemap'] ?? false),
            'seo.structured_data' => (bool) ($data['structured_data'] ?? false),
            'seo.language_links' => (bool) ($data['language_links'] ?? false),
            'seo.robots_extra' => RobotsTxt::cleanExtra((string) ($data['robots_extra'] ?? '')),
        ]);

        if ($request->hasFile('share_image')) {
            ShareImage::store($request->file('share_image'));
        }

        Sitemap::forget();
        Activity::log('settings.seo', 'Search engine settings changed');

        return back()->with('status', __('Settings saved.'));
    }

    public function removeImage(): RedirectResponse
    {
        ShareImage::remove();

        return back()->with('status', __('The share image was removed.'));
    }

    public function removeRedirect(SeoRedirect $redirect): RedirectResponse
    {
        $redirect->delete();

        return back()->with('status', __('The old address no longer forwards.'));
    }
}
