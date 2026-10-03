<?php

namespace App\Seo;

use App\Support\Locales;
use Illuminate\Http\Request;

/**
 * Writes what search engines and link previews need into a finished page: the title and
 * description, one address per page, the other language versions, the share picture, the
 * site verification codes and structured data. Works with every theme, because it changes the
 * page after the theme drew it. Tags a theme already has are left alone.
 */
class HeadTags
{
    public function __construct(private readonly Seo $seo) {}

    public function apply(string $html, Request $request): string
    {
        $end = stripos($html, '</head>');

        if ($end === false) {
            return $html;
        }

        $head = substr($html, 0, $end);
        $rest = substr($html, $end);
        $indexable = $this->seo->isIndexable($request);

        [$head, $title] = $this->title($head);
        [$head, $description] = $this->description($head);

        return $head.$this->tags($head, $request, $indexable, $title, $description)."\n".$rest;
    }

    /**
     * @return array{0: string, 1: string} The head and the title it now has.
     */
    private function title(string $head): array
    {
        $current = preg_match('#<title[^>]*>(.*?)</title>#is', $head, $match) ? self::decode($match[1]) : '';
        $title = $this->seo->title();

        // Pages without a title of their own follow the title pattern, when staff changed it.
        if ($title === null && $this->seo->pageTitle() !== null && trim((string) setting('seo.title_pattern')) !== SeoText::DEFAULT_PATTERN) {
            $title = SeoText::withPattern($this->seo->pageTitle());
        }

        if ($title === null) {
            return [$head, $current ?: (string) setting('company.name')];
        }

        $tag = '<title>'.e($title).'</title>';
        $head = $current !== '' || str_contains(strtolower($head), '<title')
            ? (string) preg_replace('#<title[^>]*>.*?</title>#is', str_replace(['\\', '$'], ['\\\\', '\\$'], $tag), $head, 1)
            : $head."\n".$tag;

        return [$head, $title];
    }

    /**
     * @return array{0: string, 1: string} The head and the description it now has.
     */
    private function description(string $head): array
    {
        $pattern = '#<meta\s[^>]*name=["\']description["\'][^>]*>#i';
        $current = '';

        if (preg_match($pattern, $head, $match) && preg_match('#content=(["\'])(.*?)\1#is', $match[0], $content)) {
            $current = self::decode($content[2]);
        }

        $description = $this->seo->description();

        if ($description === null) {
            return [$head, $current];
        }

        $tag = '<meta name="description" content="'.e($description).'">';
        $head = preg_match($pattern, $head)
            ? (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $tag), $head, 1)
            : $head."\n".$tag;

        return [$head, $description];
    }

    private function tags(string $head, Request $request, bool $indexable, string $title, string $description): string
    {
        $has = fn (string $needle): bool => stripos($head, $needle) !== false;
        $tags = [];

        if (! $indexable) {
            return $has('name="robots"') ? '' : '<meta name="robots" content="noindex, nofollow">';
        }

        // Odd values, such as ?lang[]=x, count as none.
        $lang = $request->query('lang');
        $lang = is_string($lang) && isset(Locales::enabled()[$lang]) ? $lang : null;
        // Each page of a list, such as /announcements?page=2, is a page of its own.
        $page = $request->query('page');
        $page = is_string($page) && ctype_digit($page) && (int) $page > 1 ? (int) $page : null;
        $canonical = SiteAddress::url($request->path(), $lang, $page);

        if (! $has('rel="canonical"')) {
            $tags[] = '<link rel="canonical" href="'.e($canonical).'">';
        }

        $locales = array_keys(Locales::enabled());

        if (setting('seo.language_links') && count($locales) > 1 && ! $has('hreflang=')) {
            foreach ($locales as $locale) {
                $tags[] = '<link rel="alternate" hreflang="'.e(Locales::htmlLang($locale)).'" href="'.e(SiteAddress::url($request->path(), $locale, $page)).'">';
            }

            $tags[] = '<link rel="alternate" hreflang="x-default" href="'.e(SiteAddress::url($request->path(), page: $page)).'">';
        }

        if (! $has('property="og:title"')) {
            $image = $this->seo->image() ?? ShareImage::url();
            $size = $this->seo->image() === null ? ShareImage::size() : null;

            $tags[] = '<meta property="og:type" content="'.e($this->seo->type()).'">';
            $tags[] = '<meta property="og:site_name" content="'.e((string) setting('company.name')).'">';
            $tags[] = '<meta property="og:title" content="'.e($title).'">';

            if ($description !== '') {
                $tags[] = '<meta property="og:description" content="'.e($description).'">';
            }

            $tags[] = '<meta property="og:url" content="'.e($canonical).'">';

            if ($image !== null) {
                $tags[] = '<meta property="og:image" content="'.e($image).'">';

                if ($size !== null) {
                    $tags[] = '<meta property="og:image:width" content="'.$size[0].'">';
                    $tags[] = '<meta property="og:image:height" content="'.$size[1].'">';
                }
            }

            $tags[] = '<meta name="twitter:card" content="'.($image !== null ? 'summary_large_image' : 'summary').'">';
        }

        foreach (['google-site-verification' => 'seo.google_code', 'msvalidate.01' => 'seo.bing_code'] as $name => $key) {
            $code = self::verificationCode((string) setting($key));

            if ($code !== '' && ! $has('name="'.$name.'"')) {
                $tags[] = '<meta name="'.$name.'" content="'.e($code).'">';
            }
        }

        foreach ($this->seo->data() as $data) {
            // One broken character must not empty the whole block.
            $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_INVALID_UTF8_SUBSTITUTE);

            if ($json !== false) {
                $tags[] = '<script type="application/ld+json">'.$json.'</script>';
            }
        }

        return $tags === [] ? '' : implode("\n", $tags);
    }

    /**
     * Staff may paste the whole tag Google or Bing gives them, or only the code in it.
     */
    public static function verificationCode(string $input): string
    {
        $input = trim($input);

        if (preg_match('#content=(["\'])(.*?)\1#i', $input, $match)) {
            $input = $match[2];
        }

        $input = (string) preg_replace('/^(google-site-verification|msvalidate\.01)\s*[=:]\s*/i', '', $input);

        return (string) preg_replace('/[^A-Za-z0-9_\-]/', '', $input);
    }

    private static function decode(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }
}
