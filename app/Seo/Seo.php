<?php

namespace App\Seo;

use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;

/**
 * What the current page tells search engines and apps that show link previews: its title and
 * description, whether it may be found at all, its share image and its structured data. Store
 * pages fill it in; AddSearchEngineTags writes it into the page, whatever the theme.
 *
 * Add-ons with public pages call allowIndexing() on it: every page that is not on the list
 * below is hidden from search engines unless it asks. Public lists split into pages also call
 * paginated().
 */
class Seo
{
    /**
     * Pages search engines may show. The client area, cart, checkout and sign-in pages are not here.
     */
    public const PUBLIC_ROUTES = [
        'store.index',
        'store.group',
        'store.product',
        'store.domains',
        'marketplace.index',
        'marketplace.show',
        'marketplace.developers',
        'marketplace.white-label',
        'kb.index',
        'kb.category',
        'kb.article',
        'announcements.index',
        'announcements.show',
        'network.status',
    ];

    public const TITLE_LIMIT = 60;

    public const DESCRIPTION_LIMIT = 160;

    /**
     * The whole title, for example "Starter Hosting · Fast SSD hosting". Replaces the theme's title.
     */
    private ?string $title = null;

    /**
     * The page's own title as the theme shows it ("Starter Hosting"), used with the title pattern.
     */
    private ?string $pageTitle = null;

    private ?string $description = null;

    private ?bool $indexable = null;

    private ?string $image = null;

    private string $type = 'website';

    /**
     * Which page of a list this is, after the first; see paginated().
     */
    private ?int $page = null;

    /**
     * @var list<array<string, mixed>>
     */
    private array $data = [];

    public function setTitle(?string $title): static
    {
        $this->title = self::clean($title);

        return $this;
    }

    public function setDescription(?string $description): static
    {
        $this->description = self::clean($description);

        return $this;
    }

    public function capturePageTitle(?string $title): void
    {
        $this->pageTitle ??= self::clean(html_entity_decode((string) $title, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    public function hide(): static
    {
        $this->indexable = false;

        return $this;
    }

    public function allowIndexing(): static
    {
        $this->indexable ??= true;

        return $this;
    }

    public function setImage(?string $url): static
    {
        $this->image = $url;

        return $this;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Marks the page as one page of a list that uses ?page=, such as /announcements?page=2, so
     * its address keeps the page number. Other pages ignore ?page=, and so do pages past the end
     * of the list: they point to the list itself.
     */
    public function paginated(Paginator $list): static
    {
        $this->page = $list->currentPage() > 1 && ! $list->isEmpty() ? $list->currentPage() : null;

        return $this;
    }

    /**
     * A block of structured data (schema.org) for Google.
     *
     * @param  array<string, mixed>  $data
     */
    public function addData(array $data): static
    {
        $this->data[] = $data;

        return $this;
    }

    public function title(): ?string
    {
        return $this->title;
    }

    public function pageTitle(): ?string
    {
        return $this->pageTitle;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function image(): ?string
    {
        return $this->image;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function page(): ?int
    {
        return $this->page;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function data(): array
    {
        return $this->data;
    }

    /**
     * Whether search engines may show this page: never when the site is hidden or the page is
     * private; otherwise when it is a store page or asked for it.
     */
    public function isIndexable(Request $request): bool
    {
        if (! setting('seo.visible') || $this->indexable === false) {
            return false;
        }

        return $this->indexable === true || $request->routeIs(...self::PUBLIC_ROUTES);
    }

    private static function clean(?string $text): ?string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return $text === '' ? null : $text;
    }
}
