<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Seo\Sitemap;
use App\Support\Activity;
use App\Support\ContentLanguages;
use App\Support\Settings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * News for clients: shown on the announcements page, in the RSS feed and on the client area
 * dashboard for two weeks.
 */
class AnnouncementController extends Controller
{
    public const RESERVED_SLUGS = ['feed'];

    public function index(): View
    {
        return view('admin.announcements.index', [
            'announcements' => Announcement::query()->with('translations')->orderByRaw('published_at IS NULL DESC')->newestFirst()->paginate(20),
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function settings(Request $request, Settings $settings): RedirectResponse
    {
        $settings->set('announcements.enabled', $request->boolean('enabled'));
        Sitemap::forget();

        return back()->with('status', __('Saved.'));
    }

    public function create(): View
    {
        return view('admin.announcements.form', [
            'announcement' => new Announcement(['is_published' => true, 'published_at' => now()]),
            'locale' => null,
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $announcement = Announcement::query()->create($this->validated($request));
        Activity::log('announcement.created', "Announcement {$announcement->title} added");
        Sitemap::forget();

        return redirect()->route('admin.announcements.edit', $announcement)->with('status', __('Announcement saved.'));
    }

    public function edit(Request $request, Announcement $announcement): View
    {
        $announcement->load('translations');

        return view('admin.announcements.form', [
            'announcement' => $announcement,
            'locale' => ContentLanguages::chosen($request->query('lang')),
            'languages' => ContentLanguages::others(),
        ]);
    }

    public function update(Request $request, Announcement $announcement): RedirectResponse
    {
        if ($locale = ContentLanguages::chosen($request->input('locale'))) {
            $data = $request->validate(['title' => ['nullable', 'string', 'max:190'], 'body' => ['nullable', 'string', 'max:100000']]);
            $announcement->saveTranslation($locale, $data['title'] ?? null, $data['body'] ?? null);

            return redirect()->route('admin.announcements.edit', [$announcement, 'lang' => $locale])->with('status', __('Translation saved.'));
        }

        $announcement->update($this->validated($request, $announcement));
        Sitemap::forget();

        return redirect()->route('admin.announcements.edit', $announcement)->with('status', __('Announcement saved.'));
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();
        Activity::log('announcement.deleted', "Announcement {$announcement->title} deleted");
        Sitemap::forget();

        return redirect()->route('admin.announcements.index')->with('status', __('Announcement deleted.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Announcement $announcement = null): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:190'],
            'slug' => ['nullable', 'string', 'max:190', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(self::RESERVED_SLUGS), Rule::unique('announcements', 'slug')->ignore($announcement?->id)],
            'body' => ['required', 'string', 'max:100000'],
            'published_at' => ['nullable', 'date'],
            'is_published' => ['boolean'],
        ], ['slug.regex' => __('Use only small letters, numbers and dashes.')]);

        $data['slug'] = filled($data['slug'] ?? null) ? $data['slug'] : ContentLanguages::slug(Announcement::class, $data['title'], $announcement?->id, self::RESERVED_SLUGS);
        $data['is_published'] = $request->boolean('is_published');
        $data['published_at'] = filled($data['published_at'] ?? null)
            ? Carbon::parse($data['published_at'])
            : ($data['is_published'] ? ($announcement?->published_at ?? now()) : $announcement?->published_at);

        return $data;
    }
}
