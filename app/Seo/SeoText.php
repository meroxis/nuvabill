<?php

namespace App\Seo;

use App\Enums\BillingCycle;
use App\Models\Product;
use App\Models\ProductGroup;

/**
 * The titles and descriptions search engines show for store pages: the ones staff wrote, or
 * ones made from the page's own details when they left them empty.
 */
class SeoText
{
    public const DEFAULT_PATTERN = '{page} · {company}';

    /**
     * The title pattern with the page's own title and the company name filled in.
     */
    public static function withPattern(string $page): string
    {
        $pattern = trim((string) setting('seo.title_pattern')) ?: self::DEFAULT_PATTERN;

        if (! str_contains($pattern, '{page}')) {
            $pattern = self::DEFAULT_PATTERN;
        }

        return self::trimEnds(strtr($pattern, ['{page}' => $page, '{company}' => (string) setting('company.name')]), " \t·|-–—");
    }

    /**
     * The features in a product description, one per line, without the bullets around them.
     *
     * @return list<string>
     */
    public static function featureLines(?string $description): array
    {
        return array_values(array_filter(array_map(
            fn (string $line): string => self::trimEnds($line, " \t-*•·"),
            preg_split('/\R/u', (string) $description) ?: [],
        ), fn (string $line): bool => $line !== ''));
    }

    public static function productTitle(Product $product): string
    {
        return $product->seo_title ?: self::withPattern($product->name);
    }

    public static function productDescription(Product $product): string
    {
        return $product->seo_description ?: self::suggestProductDescription($product);
    }

    public static function groupTitle(ProductGroup $group): string
    {
        return $group->seo_title ?: self::withPattern($group->name);
    }

    public static function groupDescription(ProductGroup $group): string
    {
        return $group->seo_description ?: self::limit(self::plain($group->description), Seo::DESCRIPTION_LIMIT);
    }

    /**
     * The home page description staff wrote, or one made from the product groups in the store.
     */
    public static function homeDescription(): string
    {
        $description = trim((string) setting('seo.home_description'));

        return $description !== '' ? $description : self::groupsDescription();
    }

    /**
     * "My Hosting Company: Web hosting, VPS, Domains." from the product groups in the store.
     */
    public static function groupsDescription(): string
    {
        $groups = ProductGroup::query()->visible()->whereHas('products', fn ($query) => $query->visible())->orderBy('sort_order')->orderBy('id')->pluck('name');

        return $groups->isEmpty() ? '' : self::limit(setting('company.name').': '.$groups->implode(', ').'.', Seo::DESCRIPTION_LIMIT);
    }

    public static function homeTitle(): string
    {
        return trim((string) setting('seo.home_title')) ?: (string) setting('company.name');
    }

    /**
     * A title made from the product and group names, with the company name when it fits.
     */
    public static function suggestProductTitle(Product $product): string
    {
        $parts = array_values(array_unique(array_filter([$product->name, $product->group?->name])));
        $title = implode(' · ', $parts);
        $withCompany = $title.' · '.setting('company.name');

        return mb_strlen($withCompany) <= Seo::TITLE_LIMIT ? $withCompany : self::limit($title, Seo::TITLE_LIMIT);
    }

    /**
     * "Starter Hosting: 10 GB SSD, free SSL, daily backups. From $4.99/mo." from the product's
     * description (one feature per line) and its lowest price.
     */
    public static function suggestProductDescription(Product $product, ?string $currency = null): string
    {
        $lines = self::featureLines(strip_tags((string) $product->description));
        $text = $product->name;

        if ($lines !== []) {
            $text .= ': '.rtrim(implode(', ', array_slice($lines, 0, 6)), '.').'.';
        }

        $price = self::fromPrice($product, $currency ?? (string) setting('billing.currency'));

        if ($price !== null && mb_strlen($text.' '.$price) <= Seo::DESCRIPTION_LIMIT) {
            return $text.' '.$price;
        }

        return self::limit($text, Seo::DESCRIPTION_LIMIT);
    }

    /**
     * "From $4.99/mo." for the shortest billing cycle, or nothing for a product without prices.
     */
    public static function fromPrice(Product $product, string $currency): ?string
    {
        $price = $product->startingPrice($currency);

        if ($price === null) {
            return null;
        }

        return $price->billing_cycle === BillingCycle::Free
            ? __('Free.')
            : __('From :price.', ['price' => money($price->price, $currency).$price->billing_cycle->suffix()]);
    }

    public static function plain(?string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $text)));
    }

    /**
     * Cut at a word so it fits, with an ellipsis when something was left out.
     */
    public static function limit(string $text, int $limit): string
    {
        $text = self::plain($text);

        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $cut = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($cut, ' ');

        return self::trimEnds($space !== false && $space > $limit * 0.6 ? mb_substr($cut, 0, $space) : $cut, ' ,.;:·', start: false).'…';
    }

    /**
     * trim() for every language. trim() removes single bytes, so "·" or "•" in its list also cut
     * letters such as "р", "ط" or "✓" in half; this removes whole characters only.
     */
    private static function trimEnds(string $text, string $characters, bool $start = true): string
    {
        $set = '['.preg_quote($characters, '/').']+';

        return (string) preg_replace('/'.($start ? '^'.$set.'|' : '').$set.'$/u', '', $text);
    }

    /**
     * How a length compares with what search engines show: short, good or too long.
     */
    public static function lengthState(string $text, int $limit, int $minimum): string
    {
        $length = mb_strlen($text);

        return match (true) {
            $length === 0 => 'empty',
            $length > $limit => 'long',
            $length < $minimum => 'short',
            default => 'good',
        };
    }
}
