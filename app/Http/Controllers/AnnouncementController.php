<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Seo\Seo;
use App\Support\Locales;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * News for clients and visitors, with an RSS feed.
 */
class AnnouncementController extends Controller
{
    public function index(Seo $seo): View
    {
        $this->ensureEnabled();
        $announcements = Announcement::query()->public()->withTranslation()->newestFirst()->paginate(10);
        $seo->setDescription(__('News from :company: new services, changes and planned work.', ['company' => setting('company.name')]))
            ->paginated($announcements);

        // No news yet: keep the empty page out of search results until the first announcement.
        if ($announcements->total() === 0) {
            $seo->hideButFollowLinks();
        }

        return view('theme::announcements.index', [
            'announcements' => $announcements,
        ]);
    }

    public function show(string $announcement, Seo $seo): View
    {
        $this->ensureEnabled();
        $announcement = Announcement::query()->public()->withTranslation()->where('slug', $announcement)->firstOrFail();
        $seo->setDescription($announcement->excerpt())->setType('article')->setReadyLanguages($announcement->readyLocales());

        return view('theme::announcements.show', [
            'announcement' => $announcement,
            'newer' => Announcement::query()->public()->withTranslation()->where('published_at', '>', $announcement->published_at)->orderBy('published_at')->first(),
            'older' => Announcement::query()->public()->withTranslation()->where('published_at', '<', $announcement->published_at)->newestFirst()->first(),
        ]);
    }

    /**
     * The newest 20 announcements as RSS, for feed readers. Shared caches may keep the feed, so its
     * language comes from the address only (?lang=de, or the site default), never from the
     * language picked in a browser.
     */
    public function feed(Request $request): Response
    {
        $this->ensureEnabled();
        $linked = $request->query('lang');
        $linked = is_string($linked) && isset(Locales::enabled()[$linked]) ? $linked : null;

        // A feed in a linked language links to that language version, its own address included.
        return Locales::in($linked ?? Locales::default(), fn (): Response => $this->buildFeed($linked === null ? [] : ['lang' => $linked]));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function buildFeed(array $query): Response
    {
        $items = Announcement::query()->public()->withTranslation()->newestFirst()->limit(20)->get();
        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">',
            '<channel>',
            '  <title>'.e(__('Announcements').' · '.setting('company.name')).'</title>',
            '  <link>'.e(route('announcements.index', $query)).'</link>',
            '  <atom:link href="'.e(route('announcements.feed', $query)).'" rel="self" type="application/rss+xml"/>',
            '  <description>'.e(__('News from :company: new services, changes and planned work.', ['company' => setting('company.name')])).'</description>',
            '  <language>'.e(strtolower(str_replace('_', '-', app()->getLocale()))).'</language>',
        ];

        foreach ($items as $item) {
            $url = route('announcements.show', $item->slug);
            $lines[] = '  <item>';
            $lines[] = '    <title>'.e($item->localized('title')).'</title>';
            $lines[] = '    <link>'.e(route('announcements.show', ['announcement' => $item->slug] + $query)).'</link>';
            $lines[] = '    <guid isPermaLink="true">'.e($url).'</guid>';
            $lines[] = '    <pubDate>'.$item->published_at->toRfc2822String().'</pubDate>';
            $lines[] = '    <description>'.e($item->html()).'</description>';
            $lines[] = '  </item>';
        }

        $lines[] = '</channel>';
        $lines[] = '</rss>';

        return response(implode("\n", $lines)."\n", 200, [
            'Content-Type' => 'application/rss+xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=900',
        ]);
    }

    private function ensureEnabled(): void
    {
        abort_unless(setting('announcements.enabled'), 404);
    }
}
