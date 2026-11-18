<?php

namespace App\Seo;

use App\Support\Locales;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Http\Request;

/**
 * What the current page tells search engines and apps that show link previews: its title and
 * description, whether it may be found at all, its share image and its structured data. Store
 * pages fill it in; AddSearchEngineTags writes it into the page, whatever the theme.
 *
 * Add-ons with public pages call allowIndexing() on it: every page that is not on the list
 * below is hidden from search engines unless it asks. Public lists split into pages also call
 * paginated(). Pages translated into only some languages call setReadyLanguages() (0.7.4+), and
 * pages whose main address is on another site call withoutLanguageVersions() (0.7.4+).
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

    /**
     * The other languages this page is really translated into; null when every language is.
     *
     * @var list<string>|null
     */
    private ?array $readyLanguages = null;

    /**
     * The page this one is a copy of, as a path on this site.
     */
    private ?string $canonicalPath = null;

    /**
     * withoutLanguageVersions() was called: the page offers search engines no language versions.
     */
    private bool $languageVersions = true;

    private bool $followLinks = false;

    /**
     * hide() was called: search engines may not follow the page's links either.
     */
    private bool $hidden = false;

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
        $this->hidden = true;
        $this->followLinks = false;

        return $this;
    }

    /**
     * Hides the page from search engines, but lets them follow its links, for pages such as an
     * empty list or an overview that another page replaces. hide() wins when both are called.
     */
    public function hideButFollowLinks(): static
    {
        $this->indexable = false;
        $this->followLinks = ! $this->hidden;

        return $this;
    }

    public function followsLinks(): bool
    {
        return $this->followLinks;
    }

    /**
     * The languages this page is really translated into. The default language always counts, so
     * pass the others. Untranslated language versions then point search engines to the main
     * language and get no language links. Add-ons call this from their page code; null means
     * every language is ready (the default).
     *
     * @param  list<string>|null  $locales
     */
    public function setReadyLanguages(?array $locales): static
    {
        $this->readyLanguages = $locales === null ? null : array_values(array_unique(array_filter(
            $locales,
            fn (mixed $locale): bool => is_string($locale) && isset(Locales::ALL[$locale]),
        )));

        return $this;
    }

    /**
     * The default language and the ready ones, in the order of the language menu, or null when
     * every language is ready.
     *
     * @return list<string>|null
     */
    public function readyLanguages(): ?array
    {
        if ($this->readyLanguages === null) {
            return null;
        }

        $default = Locales::default();

        return array_values(array_filter(
            array_keys(Locales::enabled()),
            fn (string $locale): bool => $locale === $default || in_array($locale, $this->readyLanguages, true),
        ));
    }

    /**
     * This page is a copy of another page of this site, such as a plan shown on its group's page:
     * search engines are sent there, and the page gets no language links. Takes a path on this
     * site ("store/web-hosting"); full addresses and anything else are ignored. Null undoes it.
     */
    public function setCanonicalPath(?string $path): static
    {
        if ($path === null) {
            $this->canonicalPath = null;

            return $this;
        }

        $path = trim($path);

        if (mb_strlen($path) > 2000 || preg_match('#//|\\\\|[?\#]|[\x00-\x1F\x7F]|^[a-z][a-z0-9+.\-]*:#i', $path)) {
            return $this;
        }

        $this->canonicalPath = trim($path, '/');

        return $this;
    }

    public function canonicalPath(): ?string
    {
        return $this->canonicalPath;
    }

    /**
     * This page offers search engines no language versions: no hreflang links, no x-default and
     * no language links they can follow in the language menu, whatever "Only link languages that
     * are translated" says. For a page whose main address is on another site, which says itself
     * which language versions there are. Visitors can still pick a language. Lasts for this
     * request only.
     */
    public function withoutLanguageVersions(): void
    {
        $this->languageVersions = false;
    }

    /**
     * Whether the page may offer search engines its language versions; see withoutLanguageVersions().
     */
    public function offersLanguageVersions(): bool
    {
        return $this->languageVersions;
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
