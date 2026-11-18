<?php

namespace App\Seo;

use App\Models\Product;
use App\Models\ProductGroup;
use App\Models\ProductPrice;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Fills in what search engines see for the store's own pages: titles, descriptions, and the
 * company, product, price and breadcrumb data Google shows in its results.
 */
class StorePages
{
    public function __construct(private readonly Seo $seo) {}

    public function home(): void
    {
        $company = (string) setting('company.name');
        $locale = app()->getLocale();
        $this->seo->setTitle(SeoText::homeTitleIn($locale))->setDescription(SeoText::homeDescriptionIn($locale))
            ->setReadyLanguages(SeoTranslations::readyLocales(SeoTranslations::HOME, SeoText::homeTitle(), SeoText::homeDescription()));

        $this->data(array_filter([
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $company,
            'url' => SiteAddress::url(''),
            'email' => (string) setting('company.email') ?: null,
            'telephone' => (string) setting('company.phone') ?: null,
            'address' => (string) setting('company.address') ? SeoText::plain((string) setting('company.address')) : null,
            'logo' => ShareImage::url(),
        ]));
        $this->data([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $company,
            'url' => SiteAddress::url(''),
        ]);
    }

    public function group(ProductGroup $group): void
    {
        if ($group->seo_hidden) {
            $this->seo->hide();
        }

        $locale = app()->getLocale();
        $this->seo->setTitle(SeoText::groupTitleIn($group, $locale))->setDescription(SeoText::groupDescriptionIn($group, $locale))
            ->setReadyLanguages(SeoTranslations::readyLocales($group, SeoText::groupTitle($group), SeoText::groupDescription($group)));
        $this->breadcrumbs([[$group->name, 'store/'.$group->slug]]);
    }

    /**
     * @param  Collection<int, ProductPrice>  $prices
     */
    public function product(ProductGroup $group, Product $product, Collection $prices, bool $inStock, string $currency): void
    {
        if ($product->seo_hidden) {
            $this->seo->hide();
        }

        $locale = app()->getLocale();
        $description = SeoText::productDescriptionIn($product, $locale);
        $this->seo->setTitle(SeoText::productTitleIn($product, $locale))->setDescription($description)->setType('product')
            ->setReadyLanguages(SeoTranslations::readyLocales($product, SeoText::productTitle($product), SeoText::productDescription($product)));

        $amounts = $prices->map(fn (ProductPrice $price): int => $price->price)->values();

        if ($amounts->isNotEmpty()) {
            $availability = 'https://schema.org/'.($inStock ? 'InStock' : 'OutOfStock');
            $offers = $amounts->unique()->count() === 1
                ? ['@type' => 'Offer', 'price' => Money::toDecimal($amounts->first()), 'priceCurrency' => $currency, 'availability' => $availability, 'url' => SiteAddress::url($product->storePath())]
                : ['@type' => 'AggregateOffer', 'lowPrice' => Money::toDecimal($amounts->min()), 'highPrice' => Money::toDecimal($amounts->max()), 'offerCount' => $amounts->count(), 'priceCurrency' => $currency, 'availability' => $availability, 'url' => SiteAddress::url($product->storePath())];

            $this->data(array_filter([
                '@context' => 'https://schema.org',
                '@type' => 'Product',
                'name' => $product->name,
                'description' => $description,
                'url' => SiteAddress::url($product->storePath()),
                'image' => ShareImage::url(),
                'brand' => ['@type' => 'Brand', 'name' => (string) setting('company.name')],
                'offers' => $offers,
            ]));
        }

        $this->breadcrumbs([[$group->name, 'store/'.$group->slug], [$product->name, $product->storePath()]]);
    }

    /**
     * @param  list<array{0: string, 1: string}>  $trail  Name and path of each page after the home page.
     */
    private function breadcrumbs(array $trail): void
    {
        $items = [['@type' => 'ListItem', 'position' => 1, 'name' => (string) setting('company.name'), 'item' => SiteAddress::url('')]];

        foreach ($trail as [$name, $path]) {
            $items[] = ['@type' => 'ListItem', 'position' => count($items) + 1, 'name' => (string) $name, 'item' => SiteAddress::url($path)];
        }

        $this->data(['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function data(array $data): void
    {
        if (setting('seo.structured_data')) {
            $this->seo->addData($data);
        }
    }
}
