<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Models\Product;
use App\Models\ProductGroup;
use App\Seo\Seo;
use App\Seo\SeoText;
use Illuminate\Support\Collection;

/**
 * The titles and descriptions search engines show for the store's pages.
 */
class SearchPageChecks extends CheckGroup
{
    public function key(): string
    {
        return 'search-pages';
    }

    public function section(): string
    {
        return self::SEO;
    }

    public function title(): string
    {
        return 'Titles and descriptions';
    }

    public function description(): string
    {
        return 'What people read about your pages in search results.';
    }

    public function icon(): string
    {
        return 'search';
    }

    public function run(): array
    {
        $groups = ProductGroup::query()->visible()->where('seo_hidden', false)->orderBy('sort_order')->get();
        $products = Product::query()->visible()->where('seo_hidden', false)->whereIn('product_group_id', $groups->pluck('id'))
            ->with(['group', 'prices'])->orderBy('sort_order')->get();

        return [
            $this->homeDescription(),
            $this->descriptions($products),
            $this->duplicates($groups, $products),
            $this->longTitles($groups, $products),
            $this->longDescriptions($groups, $products),
        ];
    }

    private function homeDescription(): CheckResult
    {
        $check = $this->check('seo.home_description', 'The home page has a description', 2);

        return trim((string) setting('seo.home_description')) !== ''
            ? $check->passed()
            : $check->warning(
                'Your home page has no description of its own.',
                advice: 'Google then shows a line made from your product groups. Write one or two sentences about what you sell and why people choose you.',
                link: $this->link('admin.settings.seo.edit', 'Write one'),
            );
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    private function descriptions(Collection $products): CheckResult
    {
        $check = $this->check('seo.descriptions', 'Every product has a description', 3);
        $missing = $products->filter(fn (Product $product): bool => trim((string) $product->seo_description) === '' && SeoText::plain($product->description) === '');

        if ($missing->isEmpty()) {
            return $check->passed(':count products checked.', ['count' => $products->count()]);
        }

        return $check->warning(
            ':count products have no description at all.',
            ['count' => $missing->count()],
            'Google then picks random text from the page. Add a few features to the product, one per line, or write a search description.',
            items: $missing->take(20)->map(fn (Product $product): array => $this->productItem($product))->values()->all(),
        );
    }

    /**
     * @param  Collection<int, ProductGroup>  $groups
     * @param  Collection<int, Product>  $products
     */
    private function duplicates(Collection $groups, Collection $products): CheckResult
    {
        $check = $this->check('seo.duplicate_titles', 'Pages have different titles', 2);
        $pages = $this->pages($groups, $products);
        $twins = $pages->groupBy(fn (array $page): string => mb_strtolower($page['title']))->filter(fn (Collection $same): bool => $same->count() > 1);

        if ($twins->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(
            ':count titles are used by more than one page.',
            ['count' => $twins->count()],
            'Google may show only one of them. Give each page its own title.',
            items: $twins->flatten(1)->take(20)->map(fn (array $page): array => $page['item'] + ['value' => $page['title']])->values()->all(),
        );
    }

    /**
     * @param  Collection<int, ProductGroup>  $groups
     * @param  Collection<int, Product>  $products
     */
    private function longTitles(Collection $groups, Collection $products): CheckResult
    {
        $check = $this->check('seo.long_titles', 'Titles fit in search results', 1);
        $long = $this->pages($groups, $products)->filter(fn (array $page): bool => mb_strlen($page['title']) > Seo::TITLE_LIMIT);

        if ($long->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(
            ':count titles are longer than :limit letters.',
            ['count' => $long->count(), 'limit' => Seo::TITLE_LIMIT],
            'Google cuts them off. Put the most important words first.',
            items: $long->take(20)->map(fn (array $page): array => $page['item'] + ['value' => __(':count letters', ['count' => mb_strlen($page['title'])])])->values()->all(),
        );
    }

    /**
     * @param  Collection<int, ProductGroup>  $groups
     * @param  Collection<int, Product>  $products
     */
    private function longDescriptions(Collection $groups, Collection $products): CheckResult
    {
        $check = $this->check('seo.long_descriptions', 'Descriptions fit in search results', 1);
        $long = collect()
            ->merge($groups->filter(fn (ProductGroup $group): bool => mb_strlen((string) $group->seo_description) > Seo::DESCRIPTION_LIMIT)->map(fn (ProductGroup $group): array => $this->groupItem($group)))
            ->merge($products->filter(fn (Product $product): bool => mb_strlen((string) $product->seo_description) > Seo::DESCRIPTION_LIMIT)->map(fn (Product $product): array => $this->productItem($product)));

        if ($long->isEmpty()) {
            return $check->passed();
        }

        return $check->warning(
            ':count search descriptions are longer than :limit letters.',
            ['count' => $long->count(), 'limit' => Seo::DESCRIPTION_LIMIT],
            'Google cuts them off after about two lines.',
            items: $long->take(20)->values()->all(),
        );
    }

    /**
     * @param  Collection<int, ProductGroup>  $groups
     * @param  Collection<int, Product>  $products
     * @return Collection<int, array{title: string, item: array<string, mixed>}>
     */
    private function pages(Collection $groups, Collection $products): Collection
    {
        return collect()
            ->merge($groups->map(fn (ProductGroup $group): array => ['title' => SeoText::groupTitle($group), 'item' => $this->groupItem($group)]))
            ->merge($products->map(fn (Product $product): array => ['title' => SeoText::productTitle($product), 'item' => $this->productItem($product)]));
    }

    /**
     * @return array<string, mixed>
     */
    private function productItem(Product $product): array
    {
        return ['label' => $product->name, 'route' => 'admin.products.edit', 'parameters' => [$product->slug]];
    }

    /**
     * @return array<string, mixed>
     */
    private function groupItem(ProductGroup $group): array
    {
        return ['label' => $group->name, 'route' => 'admin.product-groups.edit', 'parameters' => [$group->slug]];
    }
}
