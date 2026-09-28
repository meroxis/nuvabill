<?php

namespace App\Health\Checks;

use App\Health\CheckGroup;
use App\Health\CheckResult;
use App\Seo\ShareImage;
use App\Support\Locales;

/**
 * Link previews on WhatsApp, Facebook and X, prices in Google results, and language versions.
 */
class SearchSharingChecks extends CheckGroup
{
    public function key(): string
    {
        return 'search-sharing';
    }

    public function section(): string
    {
        return self::SEO;
    }

    public function title(): string
    {
        return 'Sharing and Google results';
    }

    public function description(): string
    {
        return 'Link previews, prices on Google and your language versions.';
    }

    public function icon(): string
    {
        return 'star';
    }

    public function run(): array
    {
        return [
            $this->shareImage(),
            $this->structuredData(),
            $this->languages(),
        ];
    }

    private function shareImage(): CheckResult
    {
        $check = $this->check('seo.share_image', 'Shared links show a picture', 2);
        $size = ShareImage::size();

        if ($size === null) {
            return $check->warning(
                'Links shared on WhatsApp, Facebook and X show no picture.',
                advice: 'Add one share image, 1200 × 630 pixels, with your logo and a short line about your company.',
                link: $this->link('admin.settings.seo.edit', 'Add a share image'),
            );
        }

        if ($size[0] < 1200 || $size[1] < 600) {
            return $check->warning(
                'The share image is :width × :height pixels.',
                ['width' => $size[0], 'height' => $size[1]],
                'Some apps then show it small or not at all. 1200 × 630 pixels works everywhere.',
                link: $this->link('admin.settings.seo.edit', 'Replace it'),
            );
        }

        return $check->passed(':width × :height', ['width' => $size[0], 'height' => $size[1]]);
    }

    private function structuredData(): CheckResult
    {
        $check = $this->check('seo.structured_data', 'Google can show your prices', 2);

        return setting('seo.structured_data')
            ? $check->passed()
            : $check->warning(
                'Product, price and company details for Google are switched off.',
                advice: 'With them, Google can show the price and stock of a product right in its results.',
                fix: $this->fix('seo.turn_on', 'Turn them on', ['key' => 'structured_data']),
            );
    }

    private function languages(): CheckResult
    {
        $check = $this->check('seo.language_links', 'Language versions are linked', 2);
        $count = count(Locales::enabled());

        if ($count < 2) {
            return $check->skipped('Only one language is on.');
        }

        return setting('seo.language_links')
            ? $check->passed(':count languages', ['count' => $count])
            : $check->warning(
                'Search engines are not told about your :count language versions.',
                ['count' => $count],
                'Then they may show people a page in another language than theirs.',
                fix: $this->fix('seo.turn_on', 'Link them', ['key' => 'language_links']),
            );
    }
}
