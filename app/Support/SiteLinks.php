<?php

namespace App\Support;

use App\Models\Announcement;
use App\Models\KbArticle;
use App\Models\NetworkIncident;
use App\Models\Server;

/**
 * Links to the knowledge base, announcements and network status page, for the menus and footer
 * of client-area themes. A link shows only when its page is switched on and has something in it.
 * Themes can use this so new pages appear without a theme update.
 */
class SiteLinks
{
    /**
     * @return list<array{key: string, label: string, url: string, match: list<string>}>
     */
    public static function all(): array
    {
        return self::remember('links', function (): array {
            $links = [];

            if (self::hasKnowledgebase()) {
                $links[] = ['key' => 'knowledgebase', 'label' => __('Knowledge base'), 'url' => route('kb.index'), 'match' => ['kb.*']];
            }

            if (setting('announcements.enabled') && rescue(fn (): bool => Announcement::query()->public()->exists(), false, report: false)) {
                $links[] = ['key' => 'announcements', 'label' => __('Announcements'), 'url' => route('announcements.index'), 'match' => ['announcements.*']];
            }

            if (setting('status.enabled') && rescue(fn (): bool => Server::query()->where('is_active', true)->where('status_public', true)->exists()
                || NetworkIncident::query()->where(fn ($query) => $query->whereNull('resolved_at')->orWhere('resolved_at', '>=', now()->subDays(14)))->exists(), false, report: false)) {
                $links[] = ['key' => 'status', 'label' => __('Network status'), 'url' => route('network.status'), 'match' => ['network.status']];
            }

            return $links;
        });
    }

    public static function hasKnowledgebase(): bool
    {
        return self::remember('knowledgebase', fn (): bool => setting('knowledgebase.enabled') && rescue(fn (): bool => KbArticle::query()->public()->exists(), false, report: false));
    }

    public static function url(string $key): ?string
    {
        return collect(self::all())->firstWhere('key', $key)['url'] ?? null;
    }

    /**
     * Worked out once per request: layouts ask more than once.
     *
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private static function remember(string $key, \Closure $callback): mixed
    {
        $attributes = request()->attributes;
        $key = 'nuvabill.site_links.'.$key;

        if (! $attributes->has($key)) {
            $attributes->set($key, $callback());
        }

        return $attributes->get($key);
    }
}
