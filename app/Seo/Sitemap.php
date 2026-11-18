<?php

namespace App\Seo;

use App\Extensions\ExtensionManager;
use App\Models\Announcement;
use App\Models\KbArticle;
use App\Models\MarketplaceItem;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\TldPrice;
use App\Support\Locales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * sitemap.xml: every store page search engines may show, and add-on pages such as a website, with
 * their language versions. A page that says which languages it is translated into ("languages")
 * lists only those, when "Only link languages that are translated" is on. Made again within the
 * hour, and at once when a product, group or search text changes.
 */
class Sitemap
{
    public const CACHE_KEY = 'seo.sitemap';

    /**
     * @return list<array{path: string, updated: Carbon|null, languages?: list<string>|null}>
     */
    public function pages(): array
    {
        $store = (bool) config('nuvabill.marketplace.store');
        $pages = [];
        $groups = ProductGroup::query()->visible()->where('seo_hidden', false)->orderBy('sort_order')->orderBy('id')->get();
        $products = Product::query()->visible()->where('seo_hidden', false)->whereIn('product_group_id', $groups->pluck('id'))
            ->with('group')->orderBy('sort_order')->orderBy('id')->get();
        // Plan pages are listed only when they are pages of their own. Otherwise they send search
        // engines to their group page, which is listed instead.
        $productPages = (bool) setting('seo.product_pages');

        // The marketplace store's home page goes to the marketplace, so it is not listed itself. With a
        // website from an add-on, the add-on lists the home page, and the store's first page at /store
        // is left out: search engines skip it and follow its links to the groups.
        $addonHome = app(ExtensionManager::class)->homePageAddon() !== null;
        // The search texts written in other languages, for every store page in one go.
        $texts = SeoTranslations::many([
            SeoTranslations::HOME,
            ...$groups->map(fn (ProductGroup $group): string => SeoTranslations::subject($group))->all(),
            ...($productPages ? $products->map(fn (Product $product): string => SeoTranslations::subject($product))->all() : []),
        ]);
        // A page without texts in other languages is ready in the main language only, so its
        // main texts are not worked out for nothing.
        $ready = function (string $subject, callable $main) use ($texts): array {
            if (! isset($texts[$subject])) {
                return [];
            }

            [$title, $description] = $main();

            return SeoTranslations::readyLocales($subject, $title, $description, $texts[$subject]);
        };

        if (! $store && ! $addonHome) {
            $pages[] = [
                'path' => '',
                'updated' => $products->max('updated_at'),
                'languages' => $ready(SeoTranslations::HOME, fn (): array => [SeoText::homeTitle(), SeoText::homeDescription()]),
            ];
        }

        foreach ($groups as $group) {
            $inGroup = $products->where('product_group_id', $group->id);

            if ($inGroup->isNotEmpty()) {
                $pages[] = [
                    'path' => 'store/'.$group->slug,
                    'updated' => collect([$group->updated_at, $inGroup->max('updated_at')])->filter()->max(),
                    'languages' => $ready(SeoTranslations::subject($group), fn (): array => [SeoText::groupTitle($group), SeoText::groupDescription($group)]),
                ];
            }
        }

        foreach ($productPages ? $products : [] as $product) {
            $pages[] = [
                'path' => $product->storePath(),
                'updated' => $product->updated_at,
                'languages' => $ready(SeoTranslations::subject($product), fn (): array => [SeoText::productTitle($product), SeoText::productDescription($product)]),
            ];
        }

        if (TldPrice::query()->where('is_enabled', true)->exists()) {
            $pages[] = ['path' => 'domains', 'updated' => null];
        }

        if ($store) {
            $latest = MarketplaceItem::query()->live()->max('updated_at');
            $pages[] = ['path' => 'marketplace', 'updated' => $latest ? Carbon::parse($latest) : null];

            foreach (MarketplaceItem::query()->live()->orderBy('name')->get(['slug', 'updated_at']) as $item) {
                $pages[] = ['path' => 'marketplace/'.$item->slug, 'updated' => $item->updated_at];
            }

            $pages[] = ['path' => 'developers', 'updated' => null];
            $pages[] = ['path' => 'white-label', 'updated' => null];
        }

        return [...$pages, ...$this->helpPages(), ...app(ExtensionManager::class)->sitemapPages()];
    }

    /**
     * The knowledge base, announcements and the network status page, when switched on.
     *
     * @return list<array{path: string, updated: Carbon|null, languages?: list<string>|null}>
     */
    private function helpPages(): array
    {
        $pages = [];

        if (setting('knowledgebase.enabled')) {
            $articles = KbArticle::query()->public()->with(['allTranslations', 'category.allTranslations'])->orderBy('kb_category_id')->orderBy('sort_order')->orderBy('id')->limit(5000)->get();

            if ($articles->isNotEmpty()) {
                $pages[] = ['path' => 'knowledgebase', 'updated' => $articles->max('updated_at')];

                foreach ($articles->groupBy('kb_category_id') as $inCategory) {
                    $category = $inCategory->first()->category;
                    $pages[] = ['path' => 'knowledgebase/'.$category->slug, 'updated' => $inCategory->max('updated_at'), 'languages' => $category->readyLocales(false)];
                }

                foreach ($articles as $article) {
                    $pages[] = ['path' => 'knowledgebase/'.$article->category->slug.'/'.$article->slug, 'updated' => $article->updated_at, 'languages' => $article->readyLocales()];
                }
            }
        }

        if (setting('announcements.enabled')) {
            $news = Announcement::query()->public()->newestFirst()->limit(1000)->with('allTranslations')->get(['id', 'slug', 'published_at', 'updated_at']);

            if ($news->isNotEmpty()) {
                $pages[] = ['path' => 'announcements', 'updated' => $news->first()->published_at];

                foreach ($news as $item) {
                    $pages[] = ['path' => 'announcements/'.$item->slug, 'updated' => $item->updated_at, 'languages' => $item->readyLocales()];
                }
            }
        }

        if (setting('status.enabled')) {
            $pages[] = ['path' => 'network-status', 'updated' => null];
        }

        return $pages;
    }

    public function xml(): string
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), function (): string {
            $linked = setting('seo.language_links') && count(Locales::enabled()) > 1;
            $lines = [
                '<?xml version="1.0" encoding="UTF-8"?>',
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"'.($linked ? ' xmlns:xhtml="http://www.w3.org/1999/xhtml"' : '').'>',
            ];

            foreach ($this->pages() as $page) {
                $lines[] = '  <url>';
                $lines[] = '    <loc>'.e(SiteAddress::url($page['path'])).'</loc>';

                if ($page['updated'] !== null) {
                    $lines[] = '    <lastmod>'.$page['updated']->toDateString().'</lastmod>';
                }

                $offered = LanguageVersions::offered($page['languages'] ?? null);

                foreach ($offered as $locale) {
                    $lines[] = '    <xhtml:link rel="alternate" hreflang="'.e(Locales::htmlLang($locale)).'" href="'.e(SiteAddress::url($page['path'], $locale)).'"/>';
                }

                if ($offered !== []) {
                    $lines[] = '    <xhtml:link rel="alternate" hreflang="x-default" href="'.e(SiteAddress::url($page['path'])).'"/>';
                }

                $lines[] = '  </url>';
            }

            $lines[] = '</urlset>';

            return implode("\n", $lines)."\n";
        });
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
